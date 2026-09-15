<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The books a customer login can act on, one set per haulier.
 *
 * A `customers` row is one haulier's account for a shipper — its rating, its
 * VAT treatment, its unpaid invoices. That stays per company, and deliberately:
 * two carriers hauling for the same firm keep two opinions of it, exactly as
 * they would if they had never met.
 *
 * What spans companies is the login, and `customer_user` is the fan-out. This
 * class is the only thing that reads it, and it is the seam the rest of the
 * portal is built on: everything else asks for *the account at this carrier*
 * and then works inside that carrier's tenancy, so no query in the portal ever
 * runs unscoped.
 *
 * The one cross-company read is here, and it is narrow on purpose: the ids come
 * from rows that name this user, so the widest thing it can return is the set of
 * accounts that person is already entitled to.
 */
class ShipperAccounts
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Every haulier's account this login acts for, keyed by company id.
     *
     * The pivot, plus `users.customer_id` — the home record a login was created
     * against. Unioned rather than trusted to be in both, because an account
     * minted by the office (`CustomerAccountService`) sets the column, and a
     * login that predates this table has only the column.
     *
     * @return Collection<string, Customer>
     */
    public function all(User $user): Collection
    {
        $ids = $this->accountIds($user);

        if ($ids === []) {
            return new Collection;
        }

        return Customer::acrossCompanies()
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->get()
            // One account per carrier is the shape `open()` maintains; keying
            // by company keeps a duplicate from ever showing as two carriers
            // with the same name.
            ->keyBy('company_id');
    }

    /**
     * The companies this shipper has an account with.
     *
     * @return string[]
     */
    public function carrierIds(User $user): array
    {
        return $this->all($user)->keys()->map(static fn ($id): string => (string) $id)->all();
    }

    /**
     * The account this login started with.
     *
     * What the portal falls back to when a customer files a request without
     * naming a carrier — a customer the office added means their own haulier by
     * it, and so does every client written before carriers could be chosen.
     *
     * A driver or a back-office account has none, and that is a 404: the same
     * answer `DriverTripController` gives an administrator asking for "my
     * trips", and much clearer than an empty list, which reads as a customer
     * with no deliveries. A shipper who registered and has not yet chosen a
     * carrier has none either, and the portal answers that case before it
     * reaches here — there is a carrier to pick, not a record gone missing.
     */
    public function home(User $user): Customer
    {
        $accounts = $this->all($user);

        $home = $user->customer_id === null
            ? $accounts->first()
            : $accounts->firstWhere('id', $user->customer_id) ?? $accounts->first();

        abort_if($home === null, 404, 'This account is not linked to a customer record.');

        return $home;
    }

    /** The account at one named carrier, or null if there is not one yet. */
    public function at(User $user, Company $carrier): ?Customer
    {
        return $this->all($user)->get($carrier->getKey());
    }

    /**
     * The account at one carrier, opening one if this is their first request.
     *
     * This is what "the transaction goes to the company the customer picked"
     * actually means at the far end: the haulier gets an ordinary `customers`
     * row in its own books, and from that moment its desk, its invoices and its
     * ledger treat this shipper like any other. Nothing about the row says it
     * arrived through the app.
     *
     * The details are copied from whichever account the shipper already has,
     * because that is the only thing the platform knows about them — a name and
     * something to contact them on. The first time there is no such account and
     * they come off the login itself, which is all registering asked for: a
     * name, and a phone number if they gave one. The commercial terms are never
     * copied — the new carrier's rating, VAT treatment and withholding flag
     * start at that carrier's own defaults, since they are its judgement to make
     * and not the previous haulier's to hand over.
     *
     * There is no store address to copy on that first open, and there is not
     * meant to be: where a load goes out from is asked per request now. An
     * office that collects from the same door every week can write one onto
     * their own row, which is theirs to keep.
     */
    public function open(User $user, Company $carrier): Customer
    {
        $existing = $this->at($user, $carrier);

        if ($existing !== null) {
            return $existing;
        }

        $known = $this->all($user)->first();

        return DB::transaction(fn (): Customer => $this->tenant->use($carrier, function () use ($user, $known, $carrier): Customer {
            // `company_id` is stamped by the model layer from the tenant in
            // force, which is the carrier — the same path every other write in
            // the system takes.
            $customer = Customer::create([
                'name' => $known?->name ?? $user->name,
                // What the desk rings: the number they gave when they signed up,
                // and the address they signed up with when they gave none. A
                // firm the haulier cannot contact is a request they cannot
                // confirm.
                'contact' => $known?->contact ?? ($user->phone ?: $user->email),
                // The store, so a second haulier knows where the loads leave
                // from without ringing to ask. Copied rather than shared: it is
                // a column on their own row, and either office may correct it.
                'address' => $known?->address,
                'latitude' => $known?->latitude,
                'longitude' => $known?->longitude,
                'status' => StatusValue::Active->value,
            ]);

            $this->link($user, $customer);
            $this->settle($user, $customer, $carrier);
            $this->tellTheDesk($customer);

            return $customer;
        }));
    }

    /**
     * Give a login that belonged to nobody a home.
     *
     * Registering as a shipper writes a login with no company, because until a
     * carrier is picked there is no company for it to belong to. This is where
     * that ends. The first account they open becomes the one on the row, and
     * from this moment the login is an ordinary customer of an ordinary
     * haulier: `BindTenant` puts a real company in force for it, `GET /me`
     * names that company, and every path it takes afterwards is the path an
     * office-created customer has always taken.
     *
     * Written once and never moved. A shipper's second and third carriers are
     * pivot rows; the home is where they started, and moving it on every request
     * would make "which carrier is this account's" mean "whichever they used
     * last", which is not a question any screen is asking.
     */
    private function settle(User $user, Customer $customer, Company $carrier): void
    {
        if ($user->company_id !== null) {
            return;
        }

        // `forceFill`, because neither column is fillable. `company_id` is the
        // scope itself and never comes from a payload; this is the one place
        // that has any business setting it.
        $user->forceFill([
            'company_id' => $carrier->getKey(),
            'customer_id' => $customer->getKey(),
        ])->save();

        // Both relations have already been read off this instance and came back
        // null — `BindTenant` asks for the company on the way in, which is how
        // the request got here — and a loaded relation is not re-read because a
        // key changed under it. Left alone, whatever asks this same object for
        // its company next is told it has none: `GET /me` in the same breath,
        // and the request after this one wherever the process is reused. Set
        // rather than merely unset, because both are already in hand.
        $user->setRelation('company', $carrier);
        $user->setRelation('customer', $customer);
    }

    /**
     * Let the carrier's office know somebody has arrived on their books.
     *
     * A customer the desk typed in was, by definition, already a conversation.
     * One who picks them out of a list in an app is not — and the first they
     * would otherwise hear of it is a pending trip from a firm they have never
     * heard of. Sent to the people who can act on it, which is the same pair a
     * new delivery request goes to.
     *
     * Here rather than in the registration, because this is the moment a
     * haulier is actually chosen. It fires for the second carrier a shipper
     * picks as well as the first, which is right: a new name on your books is
     * news whether or not the platform had met them before.
     */
    private function tellTheDesk(Customer $customer): void
    {
        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Dispatcher],
            icon: 'customers',
            title: 'New customer registered',
            detail: $customer->name.' chose you in the app',
            tone: Tone::Info,
        );
    }

    /**
     * Record that this login may act for this account.
     *
     * `insertOrIgnore` because a link is a fact rather than a quantity: linking
     * twice is the same link, and the unique index says so.
     */
    public function link(User $user, Customer $customer): void
    {
        DB::table('customer_user')->insertOrIgnore([
            'customer_id' => $customer->getKey(),
            'user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return string[]
     */
    private function accountIds(User $user): array
    {
        $linked = DB::table('customer_user')
            ->where('user_id', $user->getKey())
            ->pluck('customer_id')
            ->all();

        $ids = $user->customer_id === null ? $linked : [...$linked, $user->customer_id];

        return array_values(array_unique(array_map(static fn ($id): string => (string) $id, $ids)));
    }
}

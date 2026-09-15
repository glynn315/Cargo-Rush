<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Customer\DTO\ShipperRegistrationData;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A shipper signing themselves up.
 *
 * The mirror of `RegistrationService`, which is how a *haulier* joins. Both are
 * public and both finish by signing the person in — because being registered
 * and then shown a login form is asking somebody to type the password they
 * chose four seconds ago.
 *
 * What is different is how much happens. A haulier's registration provisions a
 * company: books, roles, a first login, a set of defaults. This writes one row.
 * There is no `customers` row and no notification to anybody's office, because
 * nobody has been chosen to be told — a shipper picks a carrier per load, on
 * the request form, from the hauliers near wherever that load is going out
 * from. `ShipperAccounts::open()` is where a carrier first hears of them, and
 * from that moment its desk, its invoices and its ledger treat them like any
 * other customer.
 *
 * So the account exists before it belongs anywhere, and the request that
 * created it is scoped to `Tenant::NOBODY` — a company no row has. That is the
 * fail-closed reading of "belongs to nobody": every scoped read answers empty
 * rather than answering with everybody's. The two things such an account can do
 * are read the carrier directory and file the request that ends the state.
 *
 * The account is flagged `chooses_carrier`, which is the whole difference
 * between the two kinds of customer login: this one arrived at the platform and
 * may pick a different haulier next week; one an office created belongs to that
 * office and is shown no other carrier. See the migration that added the column.
 */
class ShipperRegistrationService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly AuthService $auth,
    ) {}

    /**
     * @return array{user: User, token: string|null}
     */
    public function register(ShipperRegistrationData $data, Request $request): array
    {
        /**
         * Nothing is in force, and nothing may be.
         *
         * Set before the write rather than left to `BindTenant` — this is a
         * public route, so no middleware has scoped anything yet, and a `users`
         * insert with a real company in force would silently stamp it and make
         * this shipper somebody's customer without their having chosen. `nobody()`
         * is what `User::mayHaveNoCompany()` is checked against.
         */
        $this->tenant->nobody();

        // A transaction over a single insert, so this reads the same as the
        // registration beside it and stays right if a second row is ever added
        // to the act.
        $user = DB::transaction(fn (): User => User::create([
            ...$data->userAttributes(),
            'role' => Role::Customer->value,
            // No carrier, so no `customers` row to point at yet. Written back
            // by `ShipperAccounts::open()` when they file their first request.
            'customer_id' => null,
            // The flag that says this account came to the platform rather than
            // to a company, and may therefore shop around.
            'chooses_carrier' => true,
        ]));

        // Outside the transaction on purpose: issuing a token writes a row and
        // starting a session writes a cookie, and neither should be rolled back
        // by a failure in work that is already committed.
        //
        // The abilities burnt into the token come from `permissions()`, which
        // looks for the account's `roles` row and finds none — the table is one
        // company's and this account is no company's — so it falls back to what
        // the `customer` role means in the enum. Which is correct, and is also
        // what `MeResource` reports back.
        $token = $this->auth->establish($user, $data->device_name, $request);

        return ['user' => $user, 'token' => $token];
    }
}

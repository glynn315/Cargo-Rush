<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Create an account.
 *
 * This exists so the first login is not a password committed to a seeder.
 * A fresh install has navigation and nothing else; whoever sets it up runs
 * this, chooses their own credentials, and signs in.
 *
 * Creating a driver also creates their `drivers` record, because a login
 * without one cannot be assigned a trip — the mobile app would sign them in
 * and then have nothing to show them.
 *
 * **Which company comes first**, before anything else is asked. It is not one
 * question among several: it decides which customers are offered to a customer
 * account, which company the driver record lands in, and whose books this
 * person will be able to open. Getting it wrong does not fail — it quietly
 * creates a working account in the wrong firm.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'cargo:user
        {--company= : Company name, code or id}
        {--name= : Full name}
        {--email= : Email address}
        {--role= : administrator, dispatcher, accountant, driver or customer}
        {--licence= : Licence number (drivers only)}
        {--licence-expiry= : Licence expiry, YYYY-MM-DD (drivers only)}
        {--customer= : Customer name or id (customers only)}';

    protected $description = 'Create a Cargo Rush account';

    public function __construct(private readonly Tenant $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $company = $this->companyFor();

        if ($company === null) {
            return self::FAILURE;
        }

        // Everything from here runs inside the company: the `users` row, the
        // `drivers` row behind a driver, and the customer lookup that offers
        // only firms this company actually has on file.
        return $this->tenant->use($company, fn (): int => $this->createAccount($company));
    }

    private function createAccount(Company $company): int
    {
        $name = $this->option('name') ?: text('Full name', required: true);
        $email = $this->option('email') ?: text('Email address', required: true);

        $role = $this->option('role') ?: select(
            'Role',
            array_combine(
                array_column(Role::cases(), 'value'),
                array_map(static fn (Role $r): string => $r->label(), Role::cases()),
            ),
        );

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:160', 'unique:users,email'],
                'role' => ['required', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Never accepted as an option: a password passed on the command line
        // ends up in the shell history and in `ps`.
        $secret = password('Password', required: true);

        $confirmation = password('Confirm password', required: true);

        if ($secret !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $strength = Validator::make(
            ['password' => $secret],
            ['password' => ['required', Password::min(8)->letters()->numbers()]],
        );

        if ($strength->fails()) {
            foreach ($strength->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $roleEnum = Role::from($role);

        // Resolved before the transaction, because it may prompt — and holding
        // a database transaction open while waiting on a person to answer a
        // question is a lock nobody meant to take.
        $customerId = null;

        if ($roleEnum === Role::Customer) {
            $customerId = $this->customerFor();

            // A customer login with no customer behind it is an account that
            // signs in and then has nothing to show, so this stops rather than
            // creating one and leaving somebody to notice later.
            if ($customerId === null) {
                return self::FAILURE;
            }
        }

        $user = DB::transaction(function () use ($name, $email, $secret, $roleEnum, $customerId): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($secret),
                'role' => $roleEnum->value,
                'customer_id' => $customerId,
            ]);

            if ($roleEnum === Role::Driver) {
                $this->driverFor($user);
            }

            return $user;
        });

        $this->info("Created {$user->name} <{$user->email}> as {$roleEnum->label()} at {$company->name}.");

        return self::SUCCESS;
    }

    /**
     * Which company this account belongs to.
     *
     * A single-company install never notices the question: there is one row, it
     * is the answer, and nobody is asked. That keeps the command exactly as it
     * was for every install that has not gone multi-company.
     *
     * `--company` matches an id, a code or a name, so an unattended run can use
     * whichever it has to hand. No match stops rather than guessing — an
     * account created in the wrong firm is a working login looking at somebody
     * else's fleet, which is worse than a failed command.
     */
    private function companyFor(): ?Company
    {
        $companies = Company::query()->orderBy('name')->get();

        if ($companies->isEmpty()) {
            $this->error('No companies on this install. Register one first at POST /api/v1/register.');

            return null;
        }

        $named = $this->option('company');

        if ($named !== null) {
            $match = $companies->first(
                static fn (Company $c): bool => $c->id === $named || $c->code === $named || $c->name === $named,
            );

            if ($match === null) {
                $this->error("No company matching \"{$named}\". Known: ".$companies->pluck('code')->join(', '));

                return null;
            }

            return $match;
        }

        if ($companies->count() === 1) {
            return $companies->first();
        }

        $choice = select(
            'Company',
            $companies->mapWithKeys(
                static fn (Company $c): array => [$c->id => "{$c->name} ({$c->code})"],
            )->all(),
        );

        return $companies->firstWhere('id', $choice);
    }

    /**
     * The `drivers` row behind a driver login.
     *
     * The licence is asked for because the Drivers module tracks expiry, and a
     * blank one would sit in the list looking like a data-entry mistake.
     */
    private function driverFor(User $user): void
    {
        $licence = $this->option('licence')
            ?: text('Licence number', required: true);

        $expiry = $this->option('licence-expiry')
            ?: text('Licence expiry (YYYY-MM-DD)', required: true);

        Driver::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'licence_no' => $licence,
            'licence_expiry' => $expiry,
            'status' => StatusValue::Available->value,
        ]);
    }

    /**
     * The `customers` row behind a customer login.
     *
     * Same reasoning as the driver's: a customer account with no customer
     * record signs in fine and then has nothing to show, because every portal
     * endpoint is scoped to it. Existing customers are offered first — the
     * common case is giving a firm already on the books a way in, not adding a
     * new firm — and a new one can be created here rather than sending whoever
     * is setting this up back to the web app.
     *
     * Returns null when `--customer` names something that is not on file. That
     * is a typo in an unattended run, and creating a firm called whatever was
     * mistyped is a worse outcome than stopping.
     */
    private function customerFor(): ?string
    {
        $named = $this->option('customer');
        $onFile = Customer::orderBy('name')->pluck('name', 'id')->all();

        if ($named !== null) {
            $match = Customer::where('id', $named)->orWhere('name', $named)->first();

            // Nothing on file at all is a first-run install, and the named
            // firm is simply the first one. A non-empty list with no match is
            // a mistyped name.
            if ($match === null && $onFile !== []) {
                $this->error("No customer called \"{$named}\". Add them in Customer Management first.");

                return null;
            }

            return $match?->id ?? $this->createCustomer($named);
        }

        if ($onFile === []) {
            return $this->createCustomer(text('Customer name (none on file yet)', required: true));
        }

        $addNew = '__new__';
        $choice = select('Customer', [...$onFile, $addNew => '+ Add a new customer']);

        return $choice === $addNew
            ? $this->createCustomer(text('Customer name', required: true))
            : (string) $choice;
    }

    /**
     * A new firm, with the one field the table insists on.
     *
     * `contact` is not nullable, and a customer created without it would break
     * on insert — so it is asked for rather than defaulted to an empty string
     * that somebody has to notice and fix later.
     */
    private function createCustomer(string $name): string
    {
        return Customer::create([
            'name' => $name,
            'contact' => text("Contact for {$name}", required: true),
            'status' => StatusValue::Active->value,
        ])->id;
    }
}

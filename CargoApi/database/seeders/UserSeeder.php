<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The accounts a fresh install signs in with.
 *
 * Configuration, like the navigation — not demo data. An install with no
 * account is an install nobody can open, and the first thing anyone needs to
 * do is get in and start entering the real fleet.
 *
 * One per role, so each part of the system can be reached and checked from the
 * start. Real staff accounts are added later with `php artisan cargo:user`,
 * which is also how these should eventually be replaced.
 *
 * **One company's, not every company's.** These go into the install's first
 * company — the one the tenancy migration created and backfilled everything
 * into. Seeding a known address and a shared password into every firm that
 * registers would be handing each of them an account they never asked for and
 * a password published in a repository. A company that registers gets exactly
 * one account: the person who registered it.
 *
 * The password comes from `SEED_PASSWORD` so it can be set per install rather
 * than being a value published in a repository. It is a starting password, not
 * a permanent one — see the notice this prints.
 */
class UserSeeder extends Seeder
{
    /**
     * email, name, role.
     *
     * @var array<int, array{string, string, Role}>
     */
    private const ACCOUNTS = [
        ['admin@cargorush.ph', 'Juan Dela Cruz', Role::Administrator],
        ['accounts@cargorush.ph', 'Elena Bautista', Role::Accountant],
        ['marco@cargorush.ph', 'Marco Reyes', Role::Driver],
    ];

    public function __construct(private readonly Tenant $tenant) {}

    public function run(): void
    {
        $company = Company::query()->oldest()->first();

        if ($company === null) {
            $this->command?->warn('No company to seed accounts into. Register one at POST /api/v1/register.');

            return;
        }

        $password = (string) env('SEED_PASSWORD', 'password');

        // Inside the company, so `company_id` is stamped by the model layer and
        // the `updateOrCreate` below matches within it rather than across the
        // platform.
        $this->tenant->use($company, function () use ($password): void {
            foreach (self::ACCOUNTS as [$email, $name, $role]) {
                $user = User::updateOrCreate(['email' => $email], [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'role' => $role->value,
                ]);

                if ($role === Role::Driver) {
                    $this->driverFor($user);
                }
            }
        });

        $this->command?->warn(sprintf(
            'Seeded accounts into %s and use the SEED_PASSWORD value. Change these passwords before going live.',
            $company->name,
        ));
    }

    /**
     * The `drivers` row behind a driver login.
     *
     * A driver account without one signs in fine and then has nothing to show:
     * every driver endpoint is scoped to a driver record, so the app would open
     * on five empty screens. The licence is a placeholder to be corrected in
     * Drivers Management — a blank one would sit in the list looking like a
     * data-entry mistake.
     */
    private function driverFor(User $user): void
    {
        Driver::updateOrCreate(['user_id' => $user->id], [
            'name' => $user->name,
            'licence_no' => 'TO-BE-SET',
            'licence_expiry' => now()->addYear()->toDateString(),
            'status' => StatusValue::Available->value,
        ]);
    }
}

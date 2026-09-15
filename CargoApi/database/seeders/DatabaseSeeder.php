<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A fresh install seeds **configuration only**: the navigation, and the
 * accounts that sign in to it.
 *
 * Both are configuration rather than data. The navigation rows drive the
 * whole shell in both clients, so an empty table is an app with no sidebar
 * rather than an app waiting for its first record — and an install with no
 * account is one nobody can open to enter anything into.
 *
 * Everything else — vehicles, drivers, customers, trips, the ledger — is the
 * business's own, and is entered through the UI. Nothing here invents a truck
 * that does not exist or a route nobody drives.
 *
 * Since the system became multi-company, the configuration falls into two
 * kinds, and the order below is that split rather than a preference:
 *
 *   **The platform's**, seeded once. Permissions are the vocabulary code checks
 *   for, and the navigation is the list of modules this application has. A
 *   company inventing either would produce a permission that gates nothing and
 *   a menu item that leads nowhere.
 *
 *   **Each company's**, seeded per company by `CompanySeeder`. Roles,
 *   positions and expense categories are what every office does differently,
 *   and a firm that renames them keeps the change through every deployment.
 *
 * Real staff accounts are added with `php artisan cargo:user`, which asks which
 * company they belong to and takes a typed password rather than one from a file.
 *
 * Demo data for a walkthrough is `Database\Seeders\Demo\*`, run on purpose:
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\FleetSeeder"
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ---- The platform's, and not any company's to redefine.
            //
            // Permissions first: roles are ticked against them, and the
            // navigation is filtered by them.
            PermissionSeeder::class,
            NavigationSeeder::class,

            // ---- Each company's own.
            //
            // Roles, positions and expense categories, laid down for every
            // company on the install — the same set registration gives a new
            // one. Running this after an upgrade tops up companies registered
            // before it.
            CompanySeeder::class,

            // Last, because an account holds a role and the roles have to
            // exist for it to hold one.
            UserSeeder::class,
        ]);

        $this->command?->info('Ready to sign in. Add the real fleet through the app, and further accounts with: php artisan cargo:user');
    }
}

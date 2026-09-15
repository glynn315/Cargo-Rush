<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Illuminate\Database\Seeder;

/**
 * Gives every company on the install its starting roles, positions and expense
 * categories — the same set a company registering today would be given.
 *
 * Which makes this a seeder that matters after the first run, unlike most.
 * Adding a role or a category in a future release changes what
 * `CompanyProvisioner` lays down, and a company that registered before that
 * release would never see it. Running `php artisan db:seed` after an upgrade
 * walks all of them and tops each one up.
 *
 * Nothing is overwritten. The three seeders underneath match on a `key` and
 * leave alone whatever the office has since renamed, reordered or switched off,
 * so a firm that calls its dispatchers "controllers" keeps calling them that
 * through every deployment.
 */
class CompanySeeder extends Seeder
{
    public function __construct(private readonly CompanyProvisioner $provisioner) {}

    public function run(): void
    {
        // Soft-deleted companies are skipped. Their rows are still in every
        // table, but nobody signs in to one, and seeding configuration into a
        // closed account is work with no reader.
        $companies = Company::query()->orderBy('created_at')->get();

        if ($companies->isEmpty()) {
            // Only reachable if the tenancy migration's default company was
            // removed by hand. Worth saying out loud rather than reporting a
            // successful run that seeded nothing.
            $this->command?->warn('No companies on this install — register one at POST /api/v1/register.');

            return;
        }

        foreach ($companies as $company) {
            $this->provisioner->provision($company);
        }

        $this->command?->info(sprintf(
            'Roles, positions and expense categories are up to date for %d compan%s.',
            $companies->count(),
            $companies->count() === 1 ? 'y' : 'ies',
        ));
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Illuminate\Database\Seeder;

/**
 * Neighbours, so the carrier list is a choice.
 *
 * The customer app opens a request by showing the hauliers near the load. On a
 * one-company install that screen is a single card, which demonstrates the
 * plumbing and none of the point — the interesting question is what a shipper
 * does when three firms are within an hour of the pallet.
 *
 * So this puts two more companies on the platform, pinned where they would
 * actually be: one across Macajalar Bay in Iligan, one down the highway in
 * Valencia. They are whole companies, provisioned exactly as a registration
 * would leave them (roles, positions, expense categories), with a small fleet
 * so the cards can say what each could put on the road. What they do not have
 * is people: nobody can sign in to them, because a demo does not need a second
 * set of credentials to keep straight and a request filed with one is still
 * visible in the database.
 *
 * It also drops the pin on the demo company itself, which is the more important
 * half — without one it is the install's own fleet that is missing from the list
 * a customer picks from.
 *
 * Idempotent on `code`, like every other seeder here: run it twice and the
 * second run tops up rather than doubling the platform.
 */
class CarrierSeeder extends Seeder
{
    use SeedsIntoACompany;

    /**
     * Where the demo company's own yard is: Iponan, Cagayan de Oro.
     *
     * The same point both clients centre a fresh map on, so the fleet appears
     * where the map already opens rather than off the edge of it.
     */
    private const HOME = [8.4856, 124.5808, 'Iponan, Cagayan de Oro'];

    /**
     * The neighbours. Name, code, phone, address, latitude, longitude.
     *
     * Real places at real distances — roughly 80 km and 100 km from Iponan —
     * because the whole feature is a sort by distance and two carriers pinned
     * in the same barangay would not show it doing anything.
     */
    private const NEIGHBOURS = [
        [
            'Bay Coast Logistics',
            'bay-coast-logistics',
            '0917 555 0110',
            'Tibanga Highway, Iligan City',
            8.2280,
            124.2452,
            [
                ['ILI 1120', 'Isuzu Elf 4W', 4000, 'active'],
                ['ILI 3345', 'Hino 300', 3500, 'available'],
                ['ILI 8890', 'Fuso Canter', 6000, 'available'],
            ],
        ],
        [
            'Bukidnon Highland Freight',
            'bukidnon-highland-freight',
            '0918 555 0244',
            'Sayre Highway, Valencia, Bukidnon',
            7.9064,
            125.0944,
            [
                ['BUK 2210', 'Isuzu Forward', 8000, 'active'],
                ['BUK 4460', 'Hino 500', 8000, 'active'],
                ['BUK 7712', 'Hyundai Mighty', 3000, 'maintenance'],
            ],
        ],
    ];

    public function run(): void
    {
        $this->pinTheHomeYard();

        foreach (self::NEIGHBOURS as [$name, $code, $phone, $address, $lat, $lng, $fleet]) {
            // `withTrashed`, because `companies.code` is unique across deleted
            // rows too — a re-run after somebody removed one of these would
            // otherwise collide on the insert rather than adopt the row.
            $company = Company::withTrashed()->firstWhere('code', $code)
                ?? Company::create(['name' => $name, 'code' => $code]);

            if ($company->trashed()) {
                $company->restore();
            }

            $company->update([
                'contact_name' => 'Operations desk',
                'contact_phone' => $phone,
                'address' => $address,
                'latitude' => $lat,
                'longitude' => $lng,
                'status' => StatusValue::Active->value,
            ]);

            // Roles, positions and categories — the same set registration lays
            // down, so these are ordinary companies rather than half-built ones.
            app(CompanyProvisioner::class)->provision($company);

            $this->fleetFor($company, $fleet);
        }

        $this->command?->info('Two neighbouring carriers on the platform, pinned and crewed with units.');
    }

    /**
     * The demo company's own pin.
     *
     * Left alone if somebody has already set one — an install that has moved
     * its pin to where it really is should not have it dragged back to the
     * fixture on the next demo seed.
     */
    private function pinTheHomeYard(): void
    {
        $this->intoCompany(function (Company $company): void {
            if ($company->isPinned()) {
                return;
            }

            [$lat, $lng, $address] = self::HOME;

            $company->update([
                'latitude' => $lat,
                'longitude' => $lng,
                'address' => $company->address ?: $address,
            ]);
        });
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int, 3: string}>  $rows
     */
    private function fleetFor(Company $company, array $rows): void
    {
        app(Tenant::class)->use($company, function () use ($rows): void {
            foreach ($rows as [$plate, $model, $capacity, $status]) {
                Vehicle::updateOrCreate(
                    ['plate' => $plate],
                    [
                        'model' => $model,
                        // Not nullable, and not something a demo needs to be
                        // interesting: derived from the plate so it is unique
                        // per unit without a third column of fixtures.
                        'registration_no' => 'LTO-2025-'.preg_replace('/\D/', '', $plate),
                        'capacity_kg' => $capacity,
                        'status' => $status,
                    ],
                );
            }
        });
    }
}

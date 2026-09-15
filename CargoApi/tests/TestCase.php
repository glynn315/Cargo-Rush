<?php

namespace Tests;

use App\Domain\Inspection\DTO\InspectionData;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The company every test runs inside unless it says otherwise.
     *
     * Production gets its tenant from the authenticated account, via
     * `BindTenant`. A test that builds rows with factories before it has
     * authenticated anybody has no account to get one from, and the model layer
     * refuses to create a row with no owner — correctly, because that is the
     * one thing it exists to prevent.
     *
     * So the suite opens inside a company. It is the same shape production
     * runs in rather than a hole punched in the rules for tests: the scope is
     * on, the stamp is on, and a test that leaks across companies fails here
     * exactly as it would in the app.
     */
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // The tenancy migration lays down a company for the rows it backfills,
        // and a fresh schema gets one too. Adopting it rather than making a
        // second is not tidiness: `UserSeeder` and the demo seeders write into
        // the oldest company on the install, so a test that seeds and then
        // reads would otherwise be looking in a company nothing was seeded
        // into — and would fail with an empty table rather than a wrong one,
        // which is the harder of the two to diagnose.
        $this->company = Company::query()->oldest()->first()
            ?? $this->makeCompany('Test Haulage', 'test-haulage');

        app(Tenant::class)->set($this->company);
    }

    /**
     * A second company, for the tests that need two.
     *
     * Isolation is the kind of property that can only be shown with a
     * neighbour: a test proving a query returns the right rows proves nothing
     * about the rows it should not have returned unless somebody else's exist.
     */
    protected function makeCompany(string $name, ?string $code = null): Company
    {
        return Company::create([
            'name' => $name,
            'code' => $code ?? Company::codeFor($name),
        ]);
    }

    /**
     * Clear a run's pre-trip check, so a driver can leave on it.
     *
     * A unit does not roll without a passing check (`TripService`), so every
     * test that puts a trip on the road has to do what a driver does at the
     * yard gate first. Here rather than copied into seven files — and it goes
     * through the service, so the pass is derived from the answers exactly as
     * it is on the handset rather than being written straight into the column.
     *
     * Every item answered `true`, which is the ordinary case: the tests that
     * care about a *failed* check say so themselves.
     */
    protected function passPreTripCheck(string $tripId): void
    {
        $trip = Trip::query()->findOrFail($tripId);

        $results = collect(app(InspectionService::class)->checklist())
            ->mapWithKeys(static fn (array $item): array => [$item['key'] => true])
            ->all();

        app(InspectionService::class)->submit(
            InspectionData::fromArray([
                'trip_id' => $trip->getKey(),
                'vehicle_id' => $trip->vehicle_id,
                'driver_id' => $trip->driver_id,
                'results' => $results,
            ]),
        );
    }

    /**
     * Run a closure as another company, then come back.
     *
     *     $rival = $this->makeCompany('Rival Freight');
     *     $theirTrip = $this->asCompany($rival, fn () => Trip::factory()->create());
     *
     *     // ...and now assert the current company cannot see it.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function asCompany(Company $company, \Closure $callback): mixed
    {
        return app(Tenant::class)->use($company, $callback);
    }
}

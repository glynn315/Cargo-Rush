<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Incident\Models\Incident;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\Demo\OperationsSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * What a driver can do about their own work, and only their own.
 *
 * Two things the handset offers that the API used to refuse, and both for the
 * same reason: they were reachable only through the office's own permissions.
 * The availability switch was `drivers.manage`, which is how the roster is
 * edited, and reporting an incident was `incidents.manage`, which is how the
 * desk writes one up and closes it out. No driver holds either, so the one
 * person who is present when something happens had to ring somebody.
 *
 * The fix is the shape the driver endpoints already use: scoped to the caller,
 * carrying no ids, and — where a permission makes sense at all — a `write` of
 * their own. These tests hold both halves: that a driver can now do it, and
 * that they still cannot do it to anybody else.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(OperationsSeeder::class);

    // Seeded with a login, a licence and a unit — a driver in the ordinary way.
    $this->driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::query()->firstWhere('name', 'Marco Reyes');
    $this->otherDriver = Driver::query()->firstWhere('name', 'Liza Tan');
    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
});

describe('a driver saying whether they are free', function (): void {
    it('can flip their own switch', function (): void {
        // Idle to begin with, so the toggle has somewhere to go: a driver
        // mid-run is `active` and the service refuses to contradict the trip
        // they are demonstrably on.
        $this->driver->update(['status' => StatusValue::Inactive->value]);

        $this->actingAs($this->driverUser)
            ->postJson('/api/v1/drivers/me/availability', ['available' => true])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Available->value);

        expect($this->driver->refresh()->status)->toBe(StatusValue::Available);
    });

    it('is not asked for a driver id at all', function (): void {
        // The old route was `drivers/{driver}/availability`, behind
        // `drivers.manage` — the office's roster permission, which no driver
        // holds. A driver flipping their own switch got a 403 naming a
        // permission they were never meant to have.
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/drivers/{$this->driver->id}/availability", ['available' => true])
            ->assertForbidden();

        // The self route answers the same driver without one.
        $this->actingAs($this->driverUser)
            ->postJson('/api/v1/drivers/me/availability', ['available' => false])
            ->assertOk()
            ->assertJsonPath('data.id', $this->driver->id);
    });

    it('cannot take anybody else off the board', function (): void {
        $this->otherDriver->update(['status' => StatusValue::Available->value]);

        // There is no id to change, so the only way to try is the office route
        // — which is exactly what `drivers.manage` is for.
        $this->actingAs($this->driverUser)
            ->postJson("/api/v1/drivers/{$this->otherDriver->id}/availability", ['available' => false])
            ->assertForbidden();

        expect($this->otherDriver->refresh()->status)->toBe(StatusValue::Available);
    });

    it('answers a back-office account with a 404 rather than a wrong row', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/drivers/me/availability', ['available' => true])
            ->assertNotFound();
    });

    it('still lets the office set somebody availability', function (): void {
        $this->otherDriver->update(['status' => StatusValue::Inactive->value]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/drivers/{$this->otherDriver->id}/availability", ['available' => true])
            ->assertOk();

        expect($this->otherDriver->refresh()->status)->toBe(StatusValue::Available);
    });
});

describe('a driver reporting an incident', function (): void {
    it('files it against themselves, their unit and their run', function (): void {
        // The run they are actually on, which is what an incident on the road
        // belongs to.
        $trip = Trip::query()->firstWhere('reference', 'CR-24817');

        $response = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
            'notes' => 'Nearside rear. Waiting on a replacement.',
        ])->assertCreated();

        expect($response->json('data.reference'))->toStartWith('INC-')
            ->and($response->json('data.kind'))->toBe('Tyre blowout')
            // None of the three was in the payload: they are the scope, and the
            // system already knew all of them.
            ->and($response->json('data.driver_id'))->toBe($this->driver->id)
            ->and($response->json('data.vehicle_plate'))->toBe($trip->vehicle?->plate)
            ->and($response->json('data.trip_reference'))->toBe('CR-24817')
            // Reported, not resolved. What happens next is the desk's answer.
            ->and($response->json('data.status'))->toBe(StatusValue::Pending->value);
    });

    it('stamps the time it happened when nobody says otherwise', function (): void {
        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Traffic hold',
            'place' => 'Kennon Road km 21',
        ])->assertCreated()->json('data.reference');

        // Read back by the reference the report came home with, not by what it
        // was about: the demo fleet has a traffic hold of its own from this
        // morning, and a test that matched on the words would be reading it.
        $incident = Incident::query()->firstWhere('reference', $reference);

        // A driver reporting from the scene means now. Asking them to confirm
        // the time would be a field with one possible answer.
        expect($incident->occurred_at->diffInMinutes(now()))->toBeLessThan(2);
    });

    it('accepts a time for one written up afterwards', function (): void {
        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Cargo damage',
            'place' => 'Dumaguete depot',
            'occurred_at' => now()->subHours(3)->toIso8601String(),
        ])->assertCreated()->json('data.reference');

        expect(Incident::query()->firstWhere('reference', $reference)->occurred_at->diffInHours(now()))
            ->toBeGreaterThanOrEqual(2);
    });

    it('refuses one that has not happened yet', function (): void {
        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Overheating',
            'place' => 'Davao bypass',
            'occurred_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    });

    it('needs to say what happened and where', function (): void {
        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind', 'place']);
    });

    it('cannot pin it on another driver or another run', function (): void {
        $theirTrip = Trip::query()->firstWhere('reference', 'CR-24818');

        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
            // Ignored rather than obeyed: there is nowhere in the form for
            // any of these, which is the point of the form.
            'driver_id' => $this->otherDriver->id,
            'trip_id' => $theirTrip->id,
            'status' => StatusValue::Delivered->value,
        ])->assertCreated()->json('data.reference');

        $incident = Incident::query()->firstWhere('reference', $reference);

        expect($incident->driver_id)->toBe($this->driver->id)
            ->and($incident->trip_id)->not->toBe($theirTrip->id)
            ->and($incident->status)->toBe(StatusValue::Pending);
    });

    it('tells the office it happened', function (): void {
        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
        ])->assertCreated();

        $told = NotificationItem::query()->where('title', 'like', 'Incident INC-%')->first();

        // A report nobody is told about is a report nobody reads.
        expect($told)->not->toBeNull()
            ->and($told->detail)->toContain('Marco Reyes')
            ->and($told->tone->value)->toBe('danger');
    });

    it('reads back only their own reports', function (): void {
        $reference = $this->actingAs($this->driverUser)->postJson('/api/v1/incidents/report', [
            'kind' => 'Windscreen chip',
            'place' => 'SLEX km 58',
        ])->assertCreated()->json('data.reference');

        $mine = $this->actingAs($this->driverUser)->getJson('/api/v1/incidents/mine')
            ->assertOk()
            ->json('data');

        $names = array_unique(array_column($mine, 'driver_name'));
        $references = array_column($mine, 'reference');

        // The demo fleet has incidents against three other drivers, including
        // one of Liza Tan's from two days ago. A driver's own list is theirs
        // alone: the log itself is `incidents.view`, which is the office's.
        expect($names)->toBe(['Marco Reyes'])
            ->and($references)->toContain($reference)
            ->and($references)->not->toContain('INC-0229');
    });

    it('is refused the office log and the office write-up', function (): void {
        $this->actingAs($this->driverUser)->getJson('/api/v1/incidents')->assertForbidden();

        $this->actingAs($this->driverUser)->postJson('/api/v1/incidents', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
            'occurred_at' => now()->toIso8601String(),
            'driver_id' => $this->otherDriver->id,
        ])->assertForbidden();
    });

    it('answers a back-office account with a 404 rather than filing against nobody', function (): void {
        // An administrator holds `*`, so the permission is not what stops them
        // — there is simply no driver record for the incident to belong to.
        $this->actingAs($this->admin)->postJson('/api/v1/incidents/report', [
            'kind' => 'Tyre blowout',
            'place' => 'SLEX km 58',
        ])->assertNotFound();
    });
});

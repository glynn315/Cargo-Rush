<?php

declare(strict_types=1);

use App\Domain\Gps\Models\GpsPing;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * A position report is a position, not a caption.
 *
 * `gps_pings.location` is a string, and the handset had been filling it by
 * formatting the coordinates it already held. Nothing was missing — it was
 * being flattened into display text on the way out of the phone. These pin the
 * three things that made impossible and now do not: **plotting** a route,
 * **querying** by area, and doing either without every client agreeing on a
 * string format.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->trip = Trip::create([
        'origin' => 'Pagadian',
        'origin_lat' => 7.8257,
        'origin_lng' => 123.4370,
        'destination' => 'Ozamis',
        'destination_lat' => 8.1481,
        'destination_lng' => 123.8444,
        'cargo' => 'Assorted retail',
        'weight_kg' => 2400,
        'driver_id' => $this->driverUser->driver->id,
        'status' => StatusValue::InTransit->value,
        'scheduled_at' => now()->subHours(2),
        'distance_total_m' => 57_000,
    ]);

    $this->report = fn (array $overrides = []) => $this->actingAs($this->driverUser)
        ->postJson('/api/v1/gps/pings', [
            'trip_id' => $this->trip->id,
            'location' => 'SLEX · Sto. Tomas exit',
            'lat' => 7.9,
            'lng' => 123.5,
            'speed_kph' => 64,
            'heading' => 'NE',
            'progress_pct' => 20,
            'distance_done_m' => 11_000,
            'recorded_at' => now()->toIso8601String(),
            ...$overrides,
        ]);
});

describe('recording a position', function (): void {
    it('keeps the coordinates as numbers, beside the name a person reads', function (): void {
        ($this->report)()->assertOk();

        $ping = GpsPing::firstOrFail();

        // Both, and they are not the same thing: one is a caption, the other
        // is a point. Storing only the first is what the change fixed.
        expect($ping->location)->toBe('SLEX · Sto. Tomas exit')
            ->and($ping->lat)->toBe(7.9)
            ->and($ping->lng)->toBe(123.5)
            ->and($ping->isPlotted())->toBeTrue();
    });

    /**
     * A handset on the old build is still a truck on the road.
     *
     * Refusing its reports until somebody updates an app trades real
     * visibility for tidy data. What it gets is a report the dashboard shows
     * and the map cannot draw — which is what every report was until now.
     */
    it('still accepts a report with no coordinates at all', function (): void {
        ($this->report)(['lat' => null, 'lng' => null])->assertOk();

        expect(GpsPing::firstOrFail()->isPlotted())->toBeFalse();
    });

    /**
     * Half a coordinate is a bug, not a degraded position.
     *
     * A latitude with no longitude would put the truck on the Greenwich
     * meridian, somewhere off the coast of Ghana.
     */
    it('refuses one half of a pair', function (): void {
        ($this->report)(['lng' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lng');
    });

    it('refuses a position that is not on the earth', function (): void {
        ($this->report)(['lat' => 91.2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lat');
    });
});

describe('reading the route back', function (): void {
    /**
     * The payload this endpoint used to return was a **status readout**: a
     * location string and a progress percentage. A client could say a run was
     * 62% done and could not put the truck anywhere.
     */
    it('hands back a drawable path, a pin, and the trip endpoints', function (): void {
        $at = now()->subMinutes(10);

        foreach ([[7.85, 123.45], [7.90, 123.50], [7.95, 123.55]] as $i => [$lat, $lng]) {
            ($this->report)([
                'lat' => $lat,
                'lng' => $lng,
                'progress_pct' => 20 + $i * 10,
                'recorded_at' => $at->copy()->addMinutes($i)->toIso8601String(),
            ])->assertOk();
        }

        $body = $this->actingAs($this->admin)
            ->getJson("/api/v1/gps/trips/{$this->trip->id}/tracking")
            ->assertOk()
            ->json('data');

        // Oldest first: a polyline wants the route in the order it was driven.
        expect($body['path'])->toHaveCount(3)
            ->and(array_column($body['path'], 'lat'))->toBe([7.85, 7.9, 7.95])
            // The pin is the latest report.
            ->and($body['current'])->toBe(['lat' => 7.95, 'lng' => 123.55])
            // And the trip's own pins, so a map frames itself in one call.
            ->and($body['endpoints']['origin'])->toBe(['lat' => 7.8257, 'lng' => 123.437])
            ->and($body['endpoints']['destination'])->toBe(['lat' => 8.1481, 'lng' => 123.8444]);
    });

    /**
     * A line threaded through the gaps would draw a route the truck never took.
     */
    it('leaves the unplotted reports out of the path', function (): void {
        ($this->report)(['recorded_at' => now()->subMinutes(3)->toIso8601String()])->assertOk();
        ($this->report)([
            'lat' => null, 'lng' => null,
            'recorded_at' => now()->subMinutes(2)->toIso8601String(),
        ])->assertOk();

        $body = $this->actingAs($this->admin)
            ->getJson("/api/v1/gps/trips/{$this->trip->id}/tracking")
            ->assertOk()
            ->json('data');

        expect($body['path'])->toHaveCount(1)
            // The pin follows the *latest* report, which had no fix — so there
            // is nothing to draw, and the dashboard says so rather than
            // leaving the marker where the truck was three minutes ago.
            ->and($body['current'])->toBeNull();
    });

    it('plots every unit on the dashboard, and says which have a real fix', function (): void {
        ($this->report)()->assertOk();

        $units = $this->actingAs($this->admin)->getJson('/api/v1/gps')->assertOk()->json('data');
        $unit = collect($units)->firstWhere('reference', $this->trip->reference);

        expect($unit['lat'])->toBe(7.9)
            ->and($unit['has_fix'])->toBeTrue();
    });

    /**
     * A dispatched unit that has not reported yet is at the yard, and saying so
     * beats leaving it off the map — as long as the dashboard can tell that
     * pin apart from a live one.
     */
    it('falls back to the origin pin, flagged as not a real fix', function (): void {
        $units = $this->actingAs($this->admin)->getJson('/api/v1/gps')->assertOk()->json('data');
        $unit = collect($units)->firstWhere('reference', $this->trip->reference);

        expect($unit['lat'])->toBe(7.8257)
            ->and($unit['has_fix'])->toBeFalse();
    });
});

/**
 * The history on an existing install is not lost to the change.
 *
 * Every ping the mobile app has written holds `"7.90000, 123.50000"` — a
 * decimal pair and nothing else — which parses back exactly. This is the
 * parser the migration runs, tested directly rather than by re-typing its SQL
 * into a test, so the thing proved correct here is the thing that runs on
 * deployment.
 *
 * The `null` rows are the half that matters more. `location` is also written
 * by hand with real place names, and a pattern loose enough to find numbers
 * inside "Km 9, Sasa" would invent a position off a street number.
 */
it('recovers coordinates the handset used to stringify', function (?string $location, ?array $expected): void {
    expect(GpsPing::coordinatesFromLocation($location))->toBe($expected);
})->with([
    'a formatted pair' => ['7.90000, 123.50000', ['lat' => 7.9, 'lng' => 123.5]],
    'padded and negative' => ['  -7.9, -123.5  ', ['lat' => -7.9, 'lng' => -123.5]],
    'no space after the comma' => ['7.9,123.5', ['lat' => 7.9, 'lng' => 123.5]],

    'a place name' => ['SLEX · Sto. Tomas exit', null],
    // The one that would put a truck on a street number.
    'a place name with a comma and a number' => ['Km 9, Sasa', null],
    'a bay reference' => ['Warehouse 3, Bay 2', null],
    // Well-formed, and not a place on the earth.
    'past the pole' => ['91.20000, 123.50000', null],
    'past the meridian' => ['7.90000, 181.00000', null],
    'nothing at all' => [null, null],
]);

/** And the same parser, through the column it fills. */
it('leaves a place name without coordinates, and parses a pair', function (): void {
    foreach (['Km 9, Sasa', '7.90000, 123.50000'] as $location) {
        ($this->report)(['location' => $location, 'lat' => null, 'lng' => null])->assertOk();
    }

    foreach (GpsPing::whereNull('lat')->get() as $ping) {
        $recovered = GpsPing::coordinatesFromLocation($ping->location);

        if ($recovered !== null) {
            $ping->update($recovered);
        }
    }

    expect(GpsPing::where('location', 'Km 9, Sasa')->firstOrFail()->isPlotted())->toBeFalse()
        ->and(GpsPing::where('location', '7.90000, 123.50000')->firstOrFail()->lat)->toBe(7.9);
});

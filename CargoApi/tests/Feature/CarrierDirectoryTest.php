<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\CarrierSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * A shipper choosing who to send a load with.
 *
 * The half of the workflow that did not exist. A firm that signed itself up on
 * the platform can see the hauliers near its load, pick one, and have the
 * request land in that company's books — with an account opened there on the
 * way past.
 *
 * The customers an office typed in are not in this story and must not be: that
 * account is the haulier's, and `ShipperRegistrationTest` is where the line
 * between the two kinds is pinned down.
 *
 * What these cover is the boundary. The directory is the one read in the system
 * that crosses companies, so it is worth being explicit about what it shows (a
 * name, a yard, a phone, free units), what it does not (anything about another
 * firm's work), and that filing across the boundary still leaves every row in
 * exactly one company.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    // The demo company, pinned in Iponan, Cagayan de Oro.
    $this->company->update(['latitude' => 8.4856, 'longitude' => 124.5808]);

    // A firm that signed itself up: its own books at the demo company, and a
    // login flagged as able to shop around.
    $this->shopFirm = Customer::create([
        'name' => 'Highland Trading',
        'contact' => '0917 555 0333',
        'address' => 'Carmen, Cagayan de Oro',
        'latitude' => 8.4780,
        'longitude' => 124.6320,
    ]);

    $this->shopper = User::create([
        'name' => 'Rita Uy',
        'email' => 'rita@highlandtrading.ph',
        'password' => Hash::make('password1'),
        'role' => Role::Customer->value,
        'customer_id' => $this->shopFirm->id,
        'chooses_carrier' => true,
    ]);

    // Iligan, about 50 km west across the bay.
    $this->neighbour = $this->makeCompany('Bay Coast Logistics');
    $this->neighbour->update([
        'latitude' => 8.2280,
        'longitude' => 124.2452,
        'address' => 'Tibanga Highway, Iligan City',
        'contact_phone' => '0917 555 0110',
    ]);

    // Manila, most of the country away, and pinned — so distance has something
    // to be wrong about if the sort is wrong.
    $this->distant = $this->makeCompany('Northern Luzon Haulage');
    $this->distant->update(['latitude' => 14.5995, 'longitude' => 120.9842]);

    $this->carriers = fn (array $query = []) => $this->actingAs($this->shopper)
        ->getJson('/api/v1/portal/carriers?'.http_build_query($query));
});

describe('the carrier list', function (): void {
    it('offers the pinned, active hauliers nearest the load first', function (): void {
        $names = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])
            ->assertOk()
            ->json('data.*.name');

        // The shipper's own doorstep first, then Iligan. Manila is outside the
        // default radius and is not an answer to "who is near me".
        expect($names)->toBe([$this->company->name, 'Bay Coast Logistics']);
    });

    it('measures the distance rather than guessing at it', function (): void {
        $rows = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data');

        expect($rows[0]['distance_km'])->toBeLessThan(0.1)
            // Straight line across Macajalar Bay. Road distance is further,
            // which is the honest thing about a sort key.
            ->and($rows[1]['distance_km'])->toBeGreaterThan(40.0)
            ->and($rows[1]['distance_km'])->toBeLessThan(60.0);
    });

    it('widens when asked to', function (): void {
        $names = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808, 'radius_km' => 900])
            ->json('data.*.name');

        expect($names)->toContain('Northern Luzon Haulage');
    });

    it('hides a haulier that has not said where it is', function (): void {
        $unpinned = $this->makeCompany('Nowhere Freight');

        expect(($this->carriers)()->json('data.*.name'))->not->toContain('Nowhere Freight')
            ->and($unpinned->isPinned())->toBeFalse();
    });

    it('hides a suspended haulier', function (): void {
        $this->neighbour->update(['status' => StatusValue::Inactive->value]);

        expect(($this->carriers)()->json('data.*.name'))->not->toContain('Bay Coast Logistics');
    });

    it('says what each could put on the road, and nothing about their work', function (): void {
        // Two units free, one in the workshop — capacity is what could be sent
        // out today, not what is on the books.
        $this->asCompany($this->neighbour, function (): void {
            Vehicle::create(['plate' => 'ILI 1120', 'model' => 'Isuzu Elf', 'registration_no' => 'R-1', 'capacity_kg' => 4000, 'status' => 'active']);
            Vehicle::create(['plate' => 'ILI 3345', 'model' => 'Hino 300', 'registration_no' => 'R-2', 'capacity_kg' => 3500, 'status' => 'available']);
            Vehicle::create(['plate' => 'ILI 8890', 'model' => 'Fuso', 'registration_no' => 'R-3', 'capacity_kg' => 6000, 'status' => 'maintenance']);
        });

        $card = collect(($this->carriers)()->json('data'))->firstWhere('name', 'Bay Coast Logistics');

        expect($card['vehicles_ready'])->toBe(2)
            ->and($card['capacity_kg'])->toBe(7500)
            ->and($card['contact_phone'])->toBe('0917 555 0110')
            ->and($card['address'])->toBe('Tibanga Highway, Iligan City')
            // A shipper is choosing a haulier, not auditing one. Nothing about
            // another firm's customers, trips or money is on this record.
            ->and(array_keys($card))->not->toContain('contact_email')
            ->and(array_keys($card))->not->toContain('trips');
    });

    it('is alphabetical when the customer did not say where they are', function (): void {
        $names = ($this->carriers)()->assertOk()->json('data.*.name');

        expect($names)->toBe(['Bay Coast Logistics', $this->company->name, 'Northern Luzon Haulage'])
            // Nothing to measure from, so nothing is claimed.
            ->and(($this->carriers)()->json('data.0.distance_km'))->toBeNull();
    });

    it('finds one by name', function (): void {
        expect(($this->carriers)(['search' => 'Bay Coast'])->json('data.*.name'))
            ->toBe(['Bay Coast Logistics']);
    });

    it('refuses half a coordinate', function (): void {
        ($this->carriers)(['lat' => 8.4856])->assertStatus(422)
            ->assertJsonValidationErrors('lng');
    });

    it('is not open to a driver', function (): void {
        $driver = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        // `portal.view` is a customer's permission. A driver's handset has no
        // business browsing the platform's hauliers.
        $this->actingAs($driver)->getJson('/api/v1/portal/carriers')->assertForbidden();
    });
});

describe('filing with a carrier the shipper has never used', function (): void {
    beforeEach(function (): void {
        $this->file = fn (array $overrides = []) => $this->actingAs($this->shopper)
            ->postJson('/api/v1/portal/requests', [
                'origin' => 'Carmen, Cagayan de Oro',
                'destination' => 'Iligan',
                'cargo' => 'Chilled produce, 8 crates',
                'weight_kg' => 1800,
                'preferred_at' => now()->addDay()->toIso8601String(),
                ...$overrides,
            ]);
    });

    it('lands in that carrier books, not the shipper usual one', function (): void {
        $response = ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();

        $trip = Trip::acrossCompanies()->findOrFail($response->json('data.id'));

        expect($trip->company_id)->toBe($this->neighbour->id)
            ->and($response->json('data.carrier'))->toBe('Bay Coast Logistics')
            // Priced by the carrier that will haul it, and waiting on their
            // desk rather than on anybody else's.
            ->and($response->json('data.status'))->toBe(StatusValue::Pending->value);
    });

    it('opens an account at that carrier, with their own terms', function (): void {
        ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();

        $opened = $this->asCompany($this->neighbour, fn () => Customer::query()->get());

        expect($opened)->toHaveCount(1)
            ->and($opened->first()->name)->toBe('Highland Trading')
            // The store travels with them, so the new haulier knows where the
            // loads leave from without ringing to ask.
            ->and($opened->first()->address)->toBe('Carmen, Cagayan de Oro')
            ->and($opened->first()->isPinned())->toBeTrue()
            // A second row in a second company, not the first one moved: the
            // original haulier's account for this firm is untouched.
            ->and($opened->first()->id)->not->toBe($this->shopFirm->id);
    });

    it('reuses that account on the next request rather than opening another', function (): void {
        ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();
        ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();

        expect($this->asCompany($this->neighbour, fn () => Customer::query()->count()))->toBe(1);
    });

    it('still goes to the usual carrier when none is named', function (): void {
        // What every client written before the carrier list sends, and what a
        // shipper means by "book me a pickup" without touching the list.
        $id = ($this->file)()->assertCreated()->json('data.id');

        expect(Trip::acrossCompanies()->findOrFail($id)->company_id)->toBe($this->company->id);
    });

    it('refuses a carrier that is not taking requests', function (): void {
        $unpinned = $this->makeCompany('Nowhere Freight');

        // Not on the list, so not bookable — and a 404, because confirming the
        // company exists is itself the leak.
        ($this->file)(['carrier_id' => $unpinned->id])->assertNotFound();
    });

    it('refuses a suspended carrier, and says why', function (): void {
        $this->neighbour->update(['status' => StatusValue::Inactive->value]);

        ($this->file)(['carrier_id' => $this->neighbour->id])->assertStatus(422);
    });
});

describe('a shipper with two hauliers', function (): void {
    beforeEach(function (): void {
        $this->fileWith = fn (?string $carrierId) => $this->actingAs($this->shopper)
            ->postJson('/api/v1/portal/requests', array_filter([
                'carrier_id' => $carrierId,
                'origin' => 'Carmen, Cagayan de Oro',
                'destination' => 'Iligan',
                'cargo' => 'Chilled produce',
                'weight_kg' => 1800,
                'preferred_at' => now()->addDay()->toIso8601String(),
            ]));
    });

    it('sees both hauliers deliveries in one list, each named', function (): void {
        ($this->fileWith)(null);
        ($this->fileWith)($this->neighbour->id);

        $rows = $this->actingAs($this->shopper)->getJson('/api/v1/portal/requests')
            ->assertOk()
            ->json('data');

        expect($rows)->toHaveCount(2)
            ->and(collect($rows)->pluck('carrier')->sort()->values()->all())
            ->toBe(['Bay Coast Logistics', $this->company->name]);
    });

    it('can open a delivery held by either of them', function (): void {
        $mine = ($this->fileWith)(null)->json('data.id');
        $theirs = ($this->fileWith)($this->neighbour->id)->json('data.id');

        // The second is the one route binding could not have resolved: it lives
        // in a company the caller is not signed in to.
        $this->actingAs($this->shopper)->getJson("/api/v1/portal/requests/{$mine}")->assertOk();

        $this->actingAs($this->shopper)->getJson("/api/v1/portal/requests/{$theirs}")
            ->assertOk()
            ->assertJsonPath('data.carrier', 'Bay Coast Logistics');
    });

    it('totals the home screen across them, and breaks it down by carrier', function (): void {
        ($this->fileWith)(null);
        ($this->fileWith)($this->neighbour->id);
        ($this->fileWith)($this->neighbour->id);

        $summary = $this->actingAs($this->shopper)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->json('data');

        expect($summary['awaiting_confirmation'])->toBe(3)
            ->and($summary['carriers'])->toHaveCount(2)
            ->and(collect($summary['carriers'])->firstWhere('name', 'Bay Coast Logistics')['awaiting_confirmation'])
            ->toBe(2);
    });

    it('keeps a haulier they use on the list wherever it is', function (): void {
        // Manila is well outside the default radius, and it stays on the list
        // once there is work with them: a customer has to be able to reach a
        // carrier they are mid-conversation with.
        ($this->fileWith)($this->distant->id)->assertCreated();

        $card = collect(($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data'))
            ->firstWhere('name', 'Northern Luzon Haulage');

        expect($card)->not->toBeNull()
            ->and($card['linked'])->toBeTrue();
    });

    it('cannot read another firm work at a carrier they share', function (): void {
        // A second shipper, on the neighbour's books, with a delivery of their
        // own. Our shipper files with the same carrier — and still sees only
        // their own.
        $rivalTrip = $this->asCompany($this->neighbour, function (): Trip {
            $rival = Customer::create(['name' => 'Rival Trading', 'contact' => 'ops@rival.ph']);

            return Trip::create([
                'customer_id' => $rival->id,
                'origin' => 'Iligan',
                'destination' => 'Ozamis',
                'cargo' => 'Sacks of feed',
                'weight_kg' => 2400,
                'scheduled_at' => now()->addDay(),
            ]);
        });

        ($this->fileWith)($this->neighbour->id)->assertCreated();

        $this->actingAs($this->shopper)->getJson("/api/v1/portal/requests/{$rivalTrip->id}")
            ->assertNotFound();

        expect($this->actingAs($this->shopper)->getJson('/api/v1/portal/requests')->json('data.*.id'))
            ->not->toContain($rivalTrip->id);
    });
});

describe('the demo neighbours', function (): void {
    it('put a choice of carriers on the platform', function (): void {
        // The demo seeder's job for this feature: a one-company install shows a
        // single card, which demonstrates the plumbing and none of the point.
        $this->company->update(['latitude' => null, 'longitude' => null]);

        $this->seed(CarrierSeeder::class);

        $names = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data.*.name');

        expect($names)->toContain('Bay Coast Logistics')
            ->and($names)->toContain('Bukidnon Highland Freight')
            // And the install's own fleet, which is the more important half:
            // without a pin it would be missing from the list a customer picks
            // from.
            ->and($names)->toContain($this->company->name);
    });

    it('runs twice without doubling the platform', function (): void {
        $this->seed(CarrierSeeder::class);
        $this->seed(CarrierSeeder::class);

        expect(Company::query()->where('code', 'bay-coast-logistics')->count())->toBe(1);
    });
});

describe('a haulier own pin', function (): void {
    it('is set at registration and puts the company on the list', function (): void {
        $this->postJson('/api/v1/register', [
            'company_name' => 'Sunrise Cargo',
            'address' => 'Puerto, Cagayan de Oro',
            'latitude' => 8.5089,
            'longitude' => 124.7290,
            'name' => 'Ana Cruz',
            'email' => 'ana@sunrisecargo.ph',
            'password' => 'sunrise-cargo-2026',
            'password_confirmation' => 'sunrise-cargo-2026',
            // A token rather than a session cookie, as a handset would ask
            // for — the test client is not a stateful origin.
            'device_name' => 'test-suite',
        ])->assertCreated();

        $registered = Company::query()->firstWhere('name', 'Sunrise Cargo');

        expect($registered->isPinned())->toBeTrue()
            ->and(($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data.*.name'))
            ->toContain('Sunrise Cargo');
    });

    it('refuses half a coordinate at registration', function (): void {
        $this->postJson('/api/v1/register', [
            'company_name' => 'Half Pinned Freight',
            'latitude' => 8.5089,
            'name' => 'Ana Cruz',
            'email' => 'ana@halfpinned.ph',
            'password' => 'half-pinned-2026',
            'password_confirmation' => 'half-pinned-2026',
            'device_name' => 'test-suite',
        ])->assertStatus(422)->assertJsonValidationErrors('longitude');
    });

    it('can be moved afterwards, which is how a firm that predates the list gets listed', function (): void {
        $admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

        $this->company->update(['latitude' => null, 'longitude' => null]);

        $this->actingAs($admin)->patchJson('/api/v1/company', [
            'latitude' => 8.4856,
            'longitude' => 124.5808,
            'address' => 'Iponan, Cagayan de Oro',
        ])
            ->assertOk()
            ->assertJsonPath('data.discoverable', true)
            ->assertJsonPath('data.latitude', 8.4856);

        expect(($this->carriers)()->json('data.*.name'))->toContain($this->company->name);
    });

    it('cannot be changed by somebody without the company permission', function (): void {
        // The pin is part of the company's identity, and identity sits behind
        // `company.manage` exactly as the logo does.
        $this->actingAs($this->shopper)->patchJson('/api/v1/company', ['latitude' => 0, 'longitude' => 0])
            ->assertForbidden();
    });
});

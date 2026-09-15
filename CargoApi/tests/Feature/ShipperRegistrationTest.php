<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\Role;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\Demo\OperationsSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * The two ways a customer account comes to exist, and why they behave
 * differently.
 *
 * **The office added them.** A haulier put the firm on its books and handed
 * over credentials. That account is that company's: it books with them, reads
 * their invoices, and is shown no other carrier at all. The relationship is
 * what the fleet is paying for, and a portal that quietly offered their customer
 * three competitors would be a strange thing to sell them.
 *
 * **They signed themselves up.** A firm with a pallet and no haulier arrived at
 * the platform. Signing up asks for a name, a number and a password and nothing
 * else — who carries the load and where the load is are answered per load, on
 * the request form, by whoever is standing next to it. So the account starts
 * belonging to nobody, and the first request is what makes them somebody's
 * customer.
 *
 * The distinction is a column on the login, defaulting to the safe end, and
 * these are the tests that keep it honest in both directions.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->company->update([
        'latitude' => 8.4856,
        'longitude' => 124.5808,
        'address' => 'Iponan, Cagayan de Oro',
        'contact_phone' => '0917 555 0100',
    ]);

    $this->rival = $this->makeCompany('Bay Coast Logistics');
    $this->rival->update(['latitude' => 8.2280, 'longitude' => 124.2452]);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    // Seeded by FleetSeeder against Negros Fresh Mart: a firm the office typed
    // in, with a login the office minted.
    $this->officeCustomer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

    $this->signUp = fn (array $overrides = []) => $this->postJson('/api/v1/register/customer', [
        // One name, for the login and for a carrier's books alike: the app
        // signs up a customer, not a business.
        'name' => 'Rita Uy',
        'contact_phone' => '0917 555 0333',
        'email' => 'rita@highlandtrading.ph',
        'password' => 'highland-trading-2026',
        'password_confirmation' => 'highland-trading-2026',
        // A token rather than a session cookie, as the handset asks for.
        'device_name' => 'test-suite',
        ...$overrides,
    ]);

    $this->pickup = fn (array $overrides = []) => [
        'origin' => 'Carmen, Cagayan de Oro',
        'destination' => 'Iligan',
        'cargo' => 'Chilled produce',
        'weight_kg' => 1800,
        'preferred_at' => now()->addDay()->toIso8601String(),
        ...$overrides,
    ];
});

describe('the carrier directory', function (): void {
    it('is answered without signing in', function (): void {
        // Public, and not because it is half of the sign-up form — it is the
        // shop window, read by a firm still deciding whether to come in.
        $names = $this->getJson('/api/v1/carriers?lat=8.4856&lng=124.5808')
            ->assertOk()
            ->json('data.*.name');

        expect($names)->toBe([$this->company->name, 'Bay Coast Logistics']);
    });

    it('shows a stranger only what a haulier paints on a truck', function (): void {
        $card = $this->getJson('/api/v1/carriers')->json('data.0');

        expect($card)->toHaveKeys(['name', 'address', 'contact_phone', 'vehicles_ready', 'capacity_kg'])
            // Nobody is signed in, so there is nothing to have dealt with.
            ->and($card['linked'])->toBeFalse()
            ->and(array_keys($card))->not->toContain('contact_email');
    });
});

describe('a shipper signing themselves up', function (): void {
    it('lands signed in, as a customer of nobody yet', function (): void {
        $response = ($this->signUp)()->assertCreated();

        expect($response->json('data.role'))->toBe(Role::Customer->value)
            ->and($response->json('data.name'))->toBe('Rita Uy')
            // No haulier has been chosen, so there is no company to name and no
            // customer record to name either. Both fill in on the first request.
            ->and($response->json('data.company_id'))->toBeNull()
            ->and($response->json('data.company_name'))->toBeNull()
            ->and($response->json('data.customer_id'))->toBeNull()
            // The same shape a login answers in, so the app carries on into the
            // portal down one code path rather than two.
            ->and($response->json('meta.token'))->toBeString()
            ->and($response->json('data.chooses_carrier'))->toBeTrue();
    });

    it('asks for nothing but a person and a login', function (): void {
        // A carrier and a pin used to be part of this form. They are answered
        // per load now, so a payload carrying them is not obeyed — it is
        // ignored, and the account is opened all the same.
        ($this->signUp)([
            'carrier_id' => $this->rival->id,
            'address' => 'Carmen, Cagayan de Oro',
            'latitude' => 8.4780,
            'longitude' => 124.6320,
            'business_name' => 'Highland Trading Corporation',
        ])->assertCreated();

        // `acrossCompanies`, because the point of the row is that it is in no
        // company's books — a scoped query is asking the wrong question.
        $login = User::acrossCompanies()->firstWhere('email', 'rita@highlandtrading.ph');

        expect($login->company_id)->toBeNull()
            ->and($login->name)->toBe('Rita Uy')
            // The number is kept on the login, because there is no carrier's
            // books to write it to yet.
            ->and($login->phone)->toBe('0917 555 0333')
            ->and(Customer::acrossCompanies()->where('name', 'Rita Uy')->exists())->toBeFalse();
    });

    it('appears on nobody books until they choose', function (): void {
        ($this->signUp)();

        // Not on the books of the carrier they might pick, nor of any other:
        // choosing a haulier for somebody is the one thing this must not do. And
        // nobody's desk has been told, because there is nothing to tell them.
        expect(Customer::acrossCompanies()->where('name', 'Rita Uy')->exists())->toBeFalse()
            ->and(
                NotificationItem::acrossCompanies()
                    ->where('title', 'New customer registered')
                    ->exists()
            )->toBeFalse();
    });

    it('can read the carriers near the load, signed in', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $names = $this->withToken($token)->getJson('/api/v1/portal/carriers?lat=8.4856&lng=124.5808')
            ->assertOk()
            ->json('data.*.name');

        // An account with no carrier is exactly the account this list is for.
        expect($names)->toBe([$this->company->name, 'Bay Coast Logistics']);
    });

    it('is shown an empty portal rather than an error', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $summary = $this->withToken($token)->getJson('/api/v1/portal/summary')->assertOk();

        // A customer with no deliveries is what they are. A 404 on the first
        // screen after signing up would read as a fault.
        expect($summary->json('data.awaiting_confirmation'))->toBe(0)
            ->and($summary->json('data.carriers'))->toBe([])
            ->and($summary->json('data.customer.id'))->toBeNull();

        $this->withToken($token)->getJson('/api/v1/portal/requests')->assertOk()
            ->assertJsonPath('data', []);
        $this->withToken($token)->getJson('/api/v1/portal/invoices')->assertOk()
            ->assertJsonPath('data', []);
    });

    it('reads nobody books while it belongs to nobody', function (): void {
        // A carrier with a board full of other people's work.
        $this->seed(OperationsSeeder::class);

        expect(Trip::acrossCompanies()->count())->toBeGreaterThan(0);

        $token = ($this->signUp)()->json('meta.token');

        // None of which is theirs. Belonging to no company has to mean *no*
        // rows and never all of them, which is the one way this could have been
        // got wrong — an unscoped request reads every haulier on the platform.
        $this->withToken($token)->getJson('/api/v1/portal/requests')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->withToken($token)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->assertJsonPath('data.in_transit', 0)
            ->assertJsonPath('data.carriers', []);
    });

    it('becomes a customer of the haulier they pick on the first request', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated()
            ->assertJsonPath('data.carrier', $this->company->name);

        $firm = $this->asCompany($this->company, fn () => Customer::query()->firstWhere('name', 'Rita Uy'));

        expect($firm)->not->toBeNull()
            ->and($firm->company_id)->toBe($this->company->id)
            // The phone they gave when they signed up, carried across from the
            // login — the desk has somebody to ring.
            ->and($firm->contact)->toBe('0917 555 0333')
            // No pin and no address: where a load goes out from is asked per
            // request now, and this office may write one of its own later.
            ->and($firm->address)->toBeNull()
            ->and($firm->isPinned())->toBeFalse();
    });

    it('belongs to that haulier from then on', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        // The waiting state is over: the login now names a company, as every
        // other account in the system does, and `GET /me` says so.
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.company_name', $this->company->name)
            ->assertJsonPath('data.customer_name', 'Rita Uy')
            ->assertJsonPath('data.chooses_carrier', true);
    });

    it('tells that carrier desk, and not their drivers', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        $told = $this->asCompany(
            $this->company,
            fn () => NotificationItem::query()->where('title', 'New customer registered')->pluck('user_id'),
        );

        // A customer who picks you out of a list in an app had no phone call.
        // Somebody has to notice — the same pair a new delivery request goes to.
        expect($told)->toContain($this->admin->id)
            ->and($told->contains(null))->toBeFalse();
    });

    it('has to say who should carry the first load', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        // There is no usual haulier to fall back on, and picking one on their
        // behalf is the whole thing this must never do. An instruction, not a
        // 404 about a missing record.
        $this->withToken($token)->postJson('/api/v1/portal/requests', ($this->pickup)())
            ->assertStatus(422);

        expect(Trip::acrossCompanies()->where('cargo', 'Chilled produce')->exists())->toBeFalse();
    });

    it('cannot send to a haulier that is not taking requests', function (): void {
        $unpinned = $this->makeCompany('Nowhere Freight');
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $unpinned->id]))
            ->assertNotFound();

        // Nothing opened on their books either: a customer row at a haulier
        // that never got the request would be a stranger on it.
        expect(Customer::acrossCompanies()->where('name', 'Rita Uy')->exists())->toBeFalse();
    });

    it('can pick a different haulier for the next load', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        // The whole point of having registered with the platform rather than
        // with a company.
        $this->withToken($token)->postJson('/api/v1/portal/requests', ($this->pickup)([
            'carrier_id' => $this->rival->id,
            'destination' => 'Ozamis',
            'cargo' => 'Sacks of feed',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.carrier', 'Bay Coast Logistics');

        // Two carriers, two sets of books, and the name and number carried onto
        // both.
        $second = $this->asCompany($this->rival, fn () => Customer::query()->firstWhere('name', 'Rita Uy'));

        expect($second->contact)->toBe('0917 555 0333');
    });

    it('files a later request with the carrier they started on', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        // Once there is a home carrier, a payload that names nobody means it —
        // which is what every client written before carriers could be chosen
        // sends.
        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['cargo' => 'Boxed goods']))
            ->assertCreated()
            ->assertJsonPath('data.carrier', $this->company->name);
    });

    it('refuses an address that already has an account', function (): void {
        ($this->signUp)(['email' => 'orders@negrosfresh.ph'])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        expect(User::query()->where('name', 'Rita Uy')->exists())->toBeFalse();
    });

    it('needs a name to be booked under', function (): void {
        ($this->signUp)(['name' => ''])->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });
});

describe('a customer the office added', function (): void {
    it('is shown no other haulier at all', function (): void {
        $this->actingAs($this->officeCustomer)->getJson('/api/v1/portal/carriers')
            ->assertForbidden()
            // Said in a sentence rather than as a bare 403: the account is not
            // broken, it belongs to somebody.
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, $this->company->name));
    });

    it('cannot send a load to a different company by asking', function (): void {
        $this->actingAs($this->officeCustomer)->postJson('/api/v1/portal/requests', [
            'carrier_id' => $this->rival->id,
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce',
            'weight_kg' => 1800,
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        // Refused outright rather than quietly filed with their own haulier:
        // sending a load somewhere the customer did not choose is worse than an
        // error, and no row of theirs appears anywhere.
        expect(Trip::acrossCompanies()->where('cargo', 'Chilled produce')->exists())->toBeFalse();
    });

    it('books with their own haulier exactly as it always did', function (): void {
        $response = $this->actingAs($this->officeCustomer)->postJson('/api/v1/portal/requests', [
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce',
            'weight_kg' => 1800,
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        expect($response->json('data.carrier'))->toBe($this->company->name)
            ->and($this->officeCustomer->choosesCarrier())->toBeFalse();
    });

    it('may name their own haulier without being refused for it', function (): void {
        // Not a choice, just a client being explicit — and the same answer
        // either way. Refusing this would fail an app that always sends the
        // field it read off `me`.
        $this->actingAs($this->officeCustomer)->postJson('/api/v1/portal/requests', [
            'carrier_id' => $this->company->id,
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Boxed goods',
            'weight_kg' => 900,
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();
    });
});

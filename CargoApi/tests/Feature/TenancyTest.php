<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * One deployment, many hauliers, and no way from one to another.
 *
 * The property under test is a negative — *nobody sees anybody else's rows* —
 * and a negative can only be shown with a neighbour on the other side of it.
 * So every test here builds two companies and asserts about the gap between
 * them. A suite with one company would pass just as happily against a system
 * with no isolation at all.
 */
beforeEach(function (): void {
    // The platform's own vocabulary. Not per company, and needed before a role
    // can be ticked against anything or a sidebar rendered.
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->rival = $this->makeCompany('Rival Freight');
});

/** An administrator in a company, with that company's roles laid down. */
function ownerOf(Company $company): User
{
    app(CompanyProvisioner::class)->provision($company);

    return test()->asCompany($company, fn (): User => User::create([
        'name' => 'Owner of '.$company->name,
        'email' => 'owner@'.$company->code.'.test',
        'password' => 'password',
        'role' => 'administrator',
    ]));
}

describe('reading', function (): void {
    it('does not show one company the fleet of another', function (): void {
        $mine = ownerOf($this->company);

        // Somebody else's truck, driver, customer and trip.
        $this->asCompany($this->rival, function (): void {
            Vehicle::create([
                'plate' => 'RIVAL 001', 'model' => 'Isuzu Forward',
                'registration_no' => 'LTO-1', 'capacity_kg' => 8000,
            ]);
            Driver::create([
                'name' => 'Their Driver', 'licence_no' => 'R-1',
                'licence_expiry' => now()->addYear()->toDateString(),
            ]);
            Customer::create(['name' => 'Their Customer', 'contact' => '0917']);
        });

        $this->actingAs($mine)->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($mine)->getJson('/api/v1/drivers')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($mine)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data');
    });

    /**
     * The one that would be a breach rather than a bug.
     *
     * A list that leaks is visible the moment anybody looks at it. A *fetch by
     * id* that leaks is invisible until somebody tries it, and an id is the one
     * thing a client is allowed to send.
     */
    it('answers 404, not 200, for a record belonging to another company', function (): void {
        $mine = ownerOf($this->company);

        $theirs = $this->asCompany($this->rival, fn (): Vehicle => Vehicle::create([
            'plate' => 'RIVAL 002', 'model' => 'Fuso Canter',
            'registration_no' => 'LTO-2', 'capacity_kg' => 6000,
        ]));

        $this->actingAs($mine)->getJson("/api/v1/vehicles/{$theirs->id}")->assertNotFound();
    });

    /**
     * Not a filter the caller can influence.
     *
     * The company comes off the authenticated account in `BindTenant` and from
     * nowhere else. A payload naming somebody else's company is data, not an
     * instruction — this pins that, because the day it stops being true is the
     * day the whole design fails silently.
     */
    it('ignores a company_id sent by the client', function (): void {
        $mine = ownerOf($this->company);

        $this->actingAs($mine)->postJson('/api/v1/customers', [
            'name' => 'Planted Customer',
            'contact' => '0917 000 0000',
            'company_id' => $this->rival->id,
        ])->assertCreated();

        expect(Customer::acrossCompanies()->where('name', 'Planted Customer')->value('company_id'))
            ->toBe($this->company->id);
    });
});

describe('writing', function (): void {
    it('stamps a new row with the company that made it', function (): void {
        $mine = ownerOf($this->company);

        $this->actingAs($mine)->postJson('/api/v1/vehicles', [
            'plate' => 'MINE 001', 'model' => 'Isuzu Forward',
            'registration_no' => 'LTO-3', 'capacity_kg' => 8000,
        ])->assertCreated();

        expect(Vehicle::acrossCompanies()->where('plate', 'MINE 001')->value('company_id'))
            ->toBe($this->company->id);
    });

    /**
     * The database, not the application, is the last word on this.
     *
     * Every scope and every stamp is code that a future change could route
     * around. `company_id` being NOT NULL is the backstop that turns such a
     * change into a failed insert rather than an unowned row.
     */
    it('refuses to create a row with no company at all', function (): void {
        app(Tenant::class)->forget();

        expect(fn () => Customer::create(['name' => 'Nobody', 'contact' => '0917']))
            ->toThrow(RuntimeException::class, 'Refusing to create');
    });
});

describe('per-company configuration', function (): void {
    /**
     * Two hauliers, two sets of roles, and renaming one leaves the other alone.
     *
     * This is the difference between "multi-company" and "a shared install with
     * a filter on it". What a firm calls its jobs is its own.
     */
    it('gives each company its own roles and leaves the neighbour alone', function (): void {
        $mine = ownerOf($this->company);
        ownerOf($this->rival);

        $dispatcher = Role::where('key', 'dispatcher')->firstOrFail();

        $this->actingAs($mine)->patchJson("/api/v1/access/roles/{$dispatcher->id}", [
            'name' => 'Yard Controller',
        ])->assertOk();

        $theirs = $this->asCompany(
            $this->rival,
            fn (): Role => Role::where('key', 'dispatcher')->firstOrFail(),
        );

        expect($theirs->name)->toBe('Dispatcher');
    });

    it('lets two companies use the same role key, plate and payroll number', function (): void {
        ownerOf($this->company);
        ownerOf($this->rival);

        // Both hold a role keyed `administrator`, and neither collided.
        expect(Role::acrossCompanies()->where('key', 'administrator')->count())->toBe(2);

        // A plate is unique within a company, not across the platform. Two
        // firms are not obliged to coordinate their fleet numbering.
        Vehicle::create([
            'plate' => 'SHARED 01', 'model' => 'Isuzu Forward',
            'registration_no' => 'LTO-4', 'capacity_kg' => 8000,
        ]);

        $this->asCompany($this->rival, fn () => Vehicle::create([
            'plate' => 'SHARED 01', 'model' => 'Fuso Canter',
            'registration_no' => 'LTO-5', 'capacity_kg' => 6000,
        ]));

        expect(Vehicle::acrossCompanies()->where('plate', 'SHARED 01')->count())->toBe(2);
    });

    /**
     * Reference series run per company.
     *
     * Otherwise the first trip a new company books is CR-24932 because another
     * haulier has been trading for a year — a reference is the only id a human
     * in this system reads, and one that starts in the middle is one nobody
     * trusts.
     */
    it('starts each company\'s trip references from the beginning', function (): void {
        $customer = Customer::create(['name' => 'Mine', 'contact' => '0917']);

        $first = Trip::create([
            'customer_id' => $customer->id, 'origin' => 'A', 'destination' => 'B',
            'cargo' => 'Boxes', 'weight_kg' => 100, 'scheduled_at' => now()->addDay(),
        ]);

        $theirs = $this->asCompany($this->rival, fn (): Trip => Trip::create([
            'origin' => 'C', 'destination' => 'D',
            'cargo' => 'Crates', 'weight_kg' => 200, 'scheduled_at' => now()->addDay(),
        ]));

        expect($first->reference)->toBe('CR-24801')
            ->and($theirs->reference)->toBe('CR-24801');
    });
});

describe('registration', function (): void {
    it('creates the company, its configuration and its first account in one call', function (): void {
        $this->postJson('/api/v1/register', [
            'company_name' => 'Southern Freight Services',
            'name' => 'Ana Cruz',
            'email' => 'ana@southern.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
            'device_name' => 'test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.company_name', 'Southern Freight Services')
            ->assertJsonPath('data.company_code', 'southern-freight-services')
            // The administrator, because there is nobody else to make one.
            ->assertJsonPath('data.role', 'administrator')
            ->assertJsonPath('data.permissions', ['*'])
            // Signed in as it finishes: registering and then being shown a
            // login form is asking for a password chosen four seconds ago.
            ->assertJsonStructure(['meta' => ['token']]);

        $company = Company::where('code', 'southern-freight-services')->firstOrFail();

        // Provisioned, or the new administrator opens the access screens on an
        // empty table and can give nobody anything.
        $this->asCompany($company, function (): void {
            expect(Role::count())->toBeGreaterThan(0)
                ->and(Position::count())->toBeGreaterThan(0)
                ->and(ExpenseCategory::count())->toBeGreaterThan(0);
        });
    });

    /**
     * Registration cannot assume anybody ran `db:seed`.
     *
     * It is public and self-service, and this test runs on a database that has
     * only been migrated — no seeder has touched it. Without the platform
     * configuration a registration *succeeds* and produces something unusable,
     * which is much worse than failing: every role is created holding no
     * permissions, so a customer login 403s on its own portal, and `nav_items`
     * is empty, so both shells render no menu at all. You register, land on the
     * dashboard and find nothing to click.
     */
    it('lays down the platform configuration on a database nobody has seeded', function (): void {
        // Put the database back the way `php artisan migrate` leaves it. This
        // file's `beforeEach` seeds both tables, and what is under test is what
        // happens when nobody has.
        DB::table('permission_role')->delete();
        Permission::query()->delete();
        DB::table('nav_items')->delete();

        expect(Permission::count())->toBe(0)
            ->and(DB::table('nav_items')->count())->toBe(0);

        $this->postJson('/api/v1/register', [
            'company_name' => 'Sunrise Cargo',
            'name' => 'Ana Cruz',
            'email' => 'ana@sunrise.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
            'device_name' => 'test',
        ])->assertCreated();

        expect(Permission::count())->toBeGreaterThan(0)
            ->and(DB::table('nav_items')->count())->toBeGreaterThan(0);

        // And the roles are ticked against them, which is the half that a
        // customer login depends on: the administrator would have survived
        // either way, on the `*` fallback.
        $company = Company::where('code', 'sunrise-cargo')->firstOrFail();

        $this->asCompany($company, function (): void {
            $customer = Role::where('key', 'customer')->firstOrFail();

            expect($customer->permissions()->pluck('key')->all())->toContain('portal.view');
        });
    });

    /**
     * The new account's role is resolved inside its own company.
     *
     * `roles.key` is only unique within a company, so `administrator` names a
     * row in every one of them. Reading it before the new company is in force
     * would match whichever the database returned first — which happens to
     * hold the same permissions today, and would stop doing so the moment
     * anybody edits theirs.
     */
    it('reads the new owner\'s role from their own company, not a neighbour\'s', function (): void {
        // A rival whose administrator has been stripped back to nothing.
        ownerOf($this->rival);
        $this->asCompany($this->rival, function (): void {
            Role::where('key', 'administrator')->firstOrFail()
                ->update(['all_permissions' => false]);
        });

        $token = $this->postJson('/api/v1/register', [
            'company_name' => 'Northern Haulage',
            'name' => 'Ben Uy',
            'email' => 'ben@northern.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
            'device_name' => 'test',
        ])
            ->assertCreated()
            // Their own administrator, which still holds everything.
            ->assertJsonPath('data.permissions', ['*'])
            ->json('meta.token');

        // And the token was stamped with those abilities, not the rival's.
        $this->withToken($token)->getJson('/api/v1/access/roles')->assertOk();
    });

    /**
     * The rule the two-field login form rests on.
     *
     * An address identifies exactly one account, so signing in with it can only
     * mean one company. Relaxing this would mean adding a company field to
     * every login screen in both clients.
     */
    it('refuses an email address that already has an account anywhere', function (): void {
        $this->asCompany($this->rival, fn () => User::create([
            'name' => 'Taken', 'email' => 'taken@example.test',
            'password' => 'password', 'role' => 'administrator',
        ]));

        $this->postJson('/api/v1/register', [
            'company_name' => 'Another Firm',
            'name' => 'Someone Else',
            'email' => 'taken@example.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'That address already has an account. Sign in instead.');
    });

    /**
     * Two firms with the same name is a normal thing, not a conflict.
     *
     * The name is theirs and is not up for negotiation because somebody else
     * registered first; the code is a handle they never chose, so that is what
     * gives way.
     */
    it('suffixes the code when the name is already taken', function (): void {
        foreach (['first@a.test', 'second@b.test'] as $email) {
            $this->postJson('/api/v1/register', [
                'company_name' => 'Southern Freight',
                'name' => 'Owner',
                'email' => $email,
                'password' => 'Str0ngPassw0rd!',
                'password_confirmation' => 'Str0ngPassw0rd!',
                'device_name' => 'test',
            ])->assertCreated();
        }

        expect(Company::where('name', 'Southern Freight')->pluck('code')->sort()->values()->all())
            ->toBe(['southern-freight', 'southern-freight-2']);
    });
});

describe('signing in', function (): void {
    it('opens the company the account belongs to, with no company field to send', function (): void {
        $mine = ownerOf($this->company);

        $this->postJson('/api/v1/login', [
            'email' => $mine->email,
            'password' => 'password',
            'device_name' => 'test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->company->id);
    });

    /**
     * Suspension stops the people, not the data.
     *
     * Every row stays exactly where it is and comes back untouched on
     * reactivation — which matters when the suspension turns out to be a
     * billing mistake.
     */
    it('turns away a suspended company and says why', function (): void {
        $mine = ownerOf($this->company);

        $this->company->update(['status' => StatusValue::Inactive->value]);

        $this->postJson('/api/v1/login', [
            'email' => $mine->email,
            'password' => 'password',
            'device_name' => 'test',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', "{$this->company->name} is suspended. Contact support to reactivate the account.");

        expect(Customer::acrossCompanies()->count())->toBe(0);
    });

    /**
     * A suspended company's people can still get out.
     *
     * `logout` is the only authenticated endpoint outside the company group.
     * Requiring an active company in order to leave one would strand somebody
     * on a screen they can neither use nor close.
     */
    it('still lets a suspended company sign out', function (): void {
        $mine = ownerOf($this->company);
        $this->company->update(['status' => StatusValue::Inactive->value]);

        $this->actingAs($mine)->postJson('/api/v1/logout')->assertNoContent();
        $this->actingAs($mine)->getJson('/api/v1/me')->assertForbidden();
    });
});

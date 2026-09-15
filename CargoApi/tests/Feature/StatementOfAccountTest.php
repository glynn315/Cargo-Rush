<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

/**
 * The statement of account: how a balance got to be what it is.
 *
 * The billing page already lists invoices. A statement is the other thing an
 * accounts department asks for — a **running account**, with an opening
 * balance, every document and every payment in the order they happened, and a
 * closing balance that follows from them line by line.
 *
 * Three properties are worth defending here, and each of them is the difference
 * between a statement and a total:
 *
 *   **The opening balance.** A statement for March that opens at zero closes
 *   short by whatever February left owing. That single omission makes the
 *   document worth less than the invoices it was built from.
 *
 *   **The order.** A receipt printed above the invoice it settled reads as a
 *   credit out of nowhere, and a customer disputing a line cannot follow it.
 *
 *   **Symmetry.** One party's receivable is the other's payable, so the same
 *   report serves a supplier — with the words on the page turned round, because
 *   the closing figure is the same number with the opposite meaning.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->customer = Customer::create([
        'name' => 'Metro Grocers',
        'contact' => '0917 000 0001',
        'address' => '14 Corrales Ave, Cagayan de Oro',
        'tin' => '123-456-789-0000',
    ]);

    /** A ₱10,000 haul, billed at ₱11,200 with VAT. */
    $this->raise = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'customer_id' => $this->customer->id,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(30)->toDateString(),
            'amount_cents' => 1_000_000,
            'direction' => 'receivable',
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->settle = fn (string $invoice, int $amount, array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => $amount,
            'paid_on' => now()->toDateString(),
            'method' => 'cheque',
            'reference' => 'CHQ-88213',
            'allocations' => [['invoice_id' => $invoice, 'amount_cents' => $amount]],
            ...$overrides,
        ])->assertCreated();

    $this->statement = fn (array $query = []) => $this->actingAs($this->admin)
        ->getJson('/api/v1/billing/statement/'.$this->customer->id.'?'.http_build_query($query))
        ->assertOk()->json('data');
});

describe('the running account', function (): void {
    it('carries a balance forward line by line', function (): void {
        $first = ($this->raise)();
        $second = ($this->raise)();
        ($this->settle)($first['id'], 400_000);

        $statement = ($this->statement)();

        expect($statement['charges_cents'])->toBe(2_240_000)
            ->and($statement['credits_cents'])->toBe(400_000)
            ->and($statement['closing_balance_cents'])->toBe(1_840_000);

        // And the closing figure is the last running balance, not a total
        // computed some other way — which is what makes the page checkable.
        $lines = $statement['lines'];

        expect(end($lines)['balance_cents'])->toBe($statement['closing_balance_cents'])
            ->and($lines)->toHaveCount(3);

        expect(collect($lines)->pluck('reference')->all())
            ->toContain($first['number'], $second['number'], 'CHQ-88213');
    });

    it('puts the invoice above the payment that settled it', function (): void {
        $invoice = ($this->raise)();
        ($this->settle)($invoice['id'], 1_120_000);

        // Both dated today, so the tie-break is what decides the order — and a
        // receipt above its own invoice would read as a credit out of nowhere.
        $kinds = collect(($this->statement)()['lines'])->pluck('kind')->all();

        expect($kinds)->toBe(['invoice', 'payment']);
    });

    it('opens a period with what the one before it left owing', function (): void {
        // Last month: billed and part paid.
        $this->travelTo(now()->subMonth(), function (): void {
            $invoice = ($this->raise)();
            ($this->settle)($invoice['id'], 120_000);
        });

        $thisMonth = ($this->raise)();

        $statement = ($this->statement)(['from' => now()->startOfMonth()->toDateString()]);

        // ₱11,200 billed less ₱1,200 received, before the range opens.
        expect($statement['opening_balance_cents'])->toBe(1_000_000)
            // Only this month's document is on the page.
            ->and($statement['lines'])->toHaveCount(1)
            ->and($statement['lines'][0]['reference'])->toBe($thisMonth['number'])
            // And the closing balance is the opening plus what happened since.
            ->and($statement['closing_balance_cents'])->toBe(2_120_000);
    });

    it('opens at zero when the statement covers everything', function (): void {
        ($this->raise)();

        expect(($this->statement)()['opening_balance_cents'])->toBe(0);
    });

    it('leaves a cancelled invoice off the account', function (): void {
        $invoice = ($this->raise)();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", ['status' => 'cancelled'])
            ->assertOk();

        $statement = ($this->statement)();

        expect($statement['lines'])->toBeEmpty()
            ->and($statement['closing_balance_cents'])->toBe(0);
    });
});

describe('the aging', function (): void {
    it('counts a document from its own due date, not from when it was raised', function (): void {
        // Raised 100 days ago on 30-day terms: 70 days past due.
        ($this->raise)([
            'issued_at' => now()->subDays(100)->toDateString(),
            'due_at' => now()->subDays(70)->toDateString(),
        ]);
        // Raised on the same day but not yet due.
        ($this->raise)([
            'issued_at' => now()->subDays(100)->toDateString(),
            'due_at' => now()->addDays(5)->toDateString(),
        ]);

        $aging = ($this->statement)()['aging'];

        expect($aging['days_61_90'])->toBe(1_120_000)
            ->and($aging['current'])->toBe(1_120_000)
            ->and($aging['days_1_30'])->toBe(0)
            ->and($aging['total_cents'])->toBe(2_240_000);
    });

    it('ages what is left, not what was billed', function (): void {
        $invoice = ($this->raise)([
            'issued_at' => now()->subDays(60)->toDateString(),
            'due_at' => now()->subDays(30)->toDateString(),
        ]);
        ($this->settle)($invoice['id'], 1_000_000);

        expect(($this->statement)()['aging']['days_1_30'])->toBe(120_000);
    });

    it('drops a settled document out of the buckets entirely', function (): void {
        $invoice = ($this->raise)();
        ($this->settle)($invoice['id'], 1_120_000);

        $aging = ($this->statement)()['aging'];

        expect($aging['total_cents'])->toBe(0)
            // The document is still on the account, though — a statement that
            // hid a paid invoice would not show the customer they paid it.
            ->and(($this->statement)()['lines'])->toHaveCount(2);
    });
});

describe('the page itself', function (): void {
    it('carries both parties, the way a document that leaves the building must', function (): void {
        ($this->raise)();

        $statement = ($this->statement)();

        expect($statement['issuer']['name'])->not->toBeNull()
            ->and($statement['account']['name'])->toBe('Metro Grocers')
            ->and($statement['account']['tin'])->toBe('123-456-789-0000')
            ->and($statement['account']['address'])->toBe('14 Corrales Ave, Cagayan de Oro');
    });

    it('spells the closing balance out, as a cheque does', function (): void {
        ($this->raise)();

        expect(($this->statement)()['closing_in_words'])
            ->toContain('Eleven thousand two hundred');
    });

    it('reads the other way round for a supplier', function (): void {
        // A subcontracted carrier that is also a row in customers: billed as a
        // payable, named as a payee, and still the same account.
        ($this->raise)(['direction' => 'payable', 'payee' => 'Metro Grocers Haulage']);

        $statement = ($this->statement)(['direction' => 'payable']);

        expect($statement['title'])->toBe('Supplier Statement')
            ->and($statement['balance_label'])->toBe('Amount we owe this account')
            // The bill's own face value: a payable is not grossed up with our
            // output VAT, it arrives with whatever the supplier charged.
            ->and($statement['closing_balance_cents'])->toBe(1_000_000);

        // And the receivable statement for the same account is empty, because a
        // payable is not money anybody owes us.
        expect(($this->statement)()['closing_balance_cents'])->toBe(0);
    });

    it('treats an unreadable date as no range rather than an error', function (): void {
        ($this->raise)();

        $statement = ($this->statement)(['from' => '2026-13-45']);

        expect($statement['range']['from'])->toBeNull()
            ->and($statement['lines'])->toHaveCount(1);
    });

    it('is not readable by somebody without the billing permission', function (): void {
        $driver = User::create([
            'name' => 'Marco', 'email' => 'marco@test.test',
            'password' => 'password', 'role' => 'driver',
        ]);

        $this->actingAs($driver)
            ->getJson('/api/v1/billing/statement/'.$this->customer->id)
            ->assertForbidden();
    });
});

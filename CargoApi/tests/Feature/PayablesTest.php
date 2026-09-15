<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Payment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

/**
 * Payables — the other half of billing, and the half that gets forgotten.
 *
 * A haulier's books have two sides. Receivables are what customers owe for
 * hauls; payables are what the firm owes for the diesel, the subcontracted
 * carrier, the tyres and the repair shop. They run through the same table and
 * the same endpoints, distinguished by `direction`, and that is deliberate: one
 * party's receivable is the other's payable, so a second module for the same
 * arithmetic would be a second place to get the arithmetic wrong.
 *
 * What is actually different, and what these tests pin down:
 *
 *   **A payable names a payee, not a customer.** The repair shop is not a row
 *   in the customer table, so `payee` is free text and required in this
 *   direction — while `customer_id` becomes required in the other.
 *
 *   **No output VAT is added.** A bill arrives with whatever the supplier
 *   charged. Grossing it up by 12% the way a receivable is grossed up would
 *   invent an amount nobody billed.
 *
 *   **Money out is recorded the same way as money in.** A payment carries its
 *   own direction, so paying a supplier is a payment against a payable rather
 *   than a status flip — which means a part payment and a single cheque
 *   covering four bills both work, as they do on the receivable side.
 *
 *   **The reports read both ways.** The aging report and the statement of
 *   account each take a direction, and an office chasing money is also being
 *   chased for it.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    /**
     * A bill from a subcontracted carrier.
     *
     * Named as a payee *and* carried against a customer row, because that is
     * the case where the two directions meet: a carrier the firm both hires and
     * hauls for. The statement of account keys on the customer, so a supplier
     * that is only ever free text has a bill and an aging line but no running
     * account — which is the honest limit of a `payee` string.
     */
    $this->supplier = Customer::create([
        'name' => 'Northern Mindanao Haulage',
        'contact' => '0917 222 0044',
        'tin' => '456-789-012-0000',
    ]);

    $this->bill = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'payee' => 'Northern Mindanao Haulage',
            'customer_id' => $this->supplier->id,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(15)->toDateString(),
            'amount_cents' => 800_000,
            'direction' => 'payable',
            ...$overrides,
        ]);

    $this->payOut = fn (string $invoice, int $amount) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payments', [
            'customer_id' => $this->supplier->id,
            'direction' => 'payable',
            'amount_cents' => $amount,
            'paid_on' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TT-90218',
            'allocations' => [['invoice_id' => $invoice, 'amount_cents' => $amount]],
        ]);
});

describe('entering a bill', function (): void {
    it('takes a payee and bills exactly what the supplier charged', function (): void {
        $bill = ($this->bill)()->assertCreated()->json('data');

        expect($bill['direction'])->toBe('payable')
            ->and($bill['payee'])->toBe('Northern Mindanao Haulage')
            // No output VAT: the bill is the bill. Grossing it up would invent
            // an amount nobody charged.
            ->and($bill['amount_cents'])->toBe(800_000)
            ->and($bill['balance_cents'])->toBe(800_000)
            ->and($bill['status'])->toBe('pending');
    });

    it('insists on a payee, since there is no customer to bill', function (): void {
        ($this->bill)(['payee' => null, 'customer_id' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.payee.0', 'A payable has to name who is being paid.');
    });

    it('does not need a customer row at all', function (): void {
        // The repair shop the firm uses twice a year. Free text is the whole
        // record, and that is enough to owe somebody money.
        $bill = ($this->bill)(['customer_id' => null, 'payee' => 'Iponan Truck Repairs'])
            ->assertCreated()->json('data');

        expect($bill['payee'])->toBe('Iponan Truck Repairs')
            ->and($bill['customer_id'])->toBeNull();
    });
});

describe('paying it', function (): void {
    it('records a part payment and leaves the rest owing', function (): void {
        $bill = ($this->bill)()->assertCreated()->json('data');

        ($this->payOut)($bill['id'], 300_000)->assertCreated();

        $this->actingAs($this->admin)->getJson("/api/v1/billing/{$bill['id']}")
            ->assertOk()
            // The state a status flip could not hold: not pending, not paid.
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.paid_cents', 300_000)
            ->assertJsonPath('data.balance_cents', 500_000);
    });

    it('closes the bill when the rest goes out', function (): void {
        $bill = ($this->bill)()->assertCreated()->json('data');

        ($this->payOut)($bill['id'], 300_000)->assertCreated();
        ($this->payOut)($bill['id'], 500_000)->assertCreated();

        $this->actingAs($this->admin)->getJson("/api/v1/billing/{$bill['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.balance_cents', 0);

        // Money out, recorded in the same table as money in, with its own
        // direction on it — which is what lets one report read both ways.
        expect(Payment::query()->where('direction', 'payable')->count())->toBe(2);
    });

    it('keeps the two directions out of each other\'s totals', function (): void {
        ($this->bill)()->assertCreated();

        $receivable = $this->actingAs($this->admin)
            ->postJson('/api/v1/billing', [
                'customer_id' => $this->supplier->id,
                'issued_at' => now()->toDateString(),
                'due_at' => now()->addDays(30)->toDateString(),
                'amount_cents' => 1_000_000,
                'direction' => 'receivable',
            ])->assertCreated()->json('data');

        $totals = $this->actingAs($this->admin)->getJson('/api/v1/billing/totals')
            ->assertOk()->json('data');

        // The same firm, on both sides, and the two figures never meet: what we
        // owe them is not netted against what they owe us unless somebody
        // agrees to offset, which is a decision and not an arithmetic.
        expect($totals['payable_cents'])->toBe(800_000)
            ->and($totals['receivable_cents'])->toBe($receivable['balance_cents']);
    });
});

describe('the reports read both ways', function (): void {
    it('ages what the firm owes, from each bill\'s own due date', function (): void {
        // 40 days past due.
        ($this->bill)([
            'issued_at' => now()->subDays(55)->toDateString(),
            'due_at' => now()->subDays(40)->toDateString(),
        ])->assertCreated();
        // Not yet due, and from a supplier who is only free text — which is
        // why `customer_id` goes with it: a linked customer row is the
        // authoritative name, so leaving it set would file this bill under the
        // carrier above.
        ($this->bill)([
            'payee' => 'Iponan Truck Repairs',
            'customer_id' => null,
            'amount_cents' => 250_000,
        ])->assertCreated();

        $aging = $this->actingAs($this->admin)
            ->getJson('/api/v1/billing/aging?direction=payable')
            ->assertOk()->json('data');

        expect($aging['buckets']['31_60'])->toBe(800_000)
            ->and($aging['buckets']['current'])->toBe(250_000)
            ->and($aging['total_cents'])->toBe(1_050_000);

        // Worst first: whoever is owed the most is the payment to make.
        expect($aging['by_counterparty'][0]['counterparty'])->toBe('Northern Mindanao Haulage')
            ->and($aging['by_counterparty'][0]['total_cents'])->toBe(800_000);
    });

    it('leaves the receivable aging empty when everything is a payable', function (): void {
        ($this->bill)()->assertCreated();

        $aging = $this->actingAs($this->admin)
            ->getJson('/api/v1/billing/aging?direction=receivable')
            ->assertOk()->json('data');

        expect($aging['total_cents'])->toBe(0)
            ->and($aging['by_counterparty'])->toBeEmpty();
    });

    it('gives a supplier statement that reads the way the supplier reads it', function (): void {
        $bill = ($this->bill)()->assertCreated()->json('data');
        ($this->payOut)($bill['id'], 300_000)->assertCreated();

        $statement = $this->actingAs($this->admin)
            ->getJson("/api/v1/billing/statement/{$this->supplier->id}?direction=payable")
            ->assertOk()->json('data');

        expect($statement['title'])->toBe('Supplier Statement')
            ->and($statement['balance_label'])->toBe('Amount we owe this account')
            ->and($statement['charges_cents'])->toBe(800_000)
            ->and($statement['credits_cents'])->toBe(300_000)
            ->and($statement['closing_balance_cents'])->toBe(500_000)
            // The bill, then the transfer that part paid it.
            ->and(collect($statement['lines'])->pluck('kind')->all())->toBe(['invoice', 'payment']);
    });
});

describe('exporting', function (): void {
    it('gives the office the payables as a spreadsheet', function (): void {
        ($this->bill)()->assertCreated();

        $response = $this->actingAs($this->admin)
            ->get('/api/v1/billing/export?direction=payable')
            ->assertOk();

        $csv = $response->streamedContent();

        expect($csv)->toContain('Northern Mindanao Haulage')
            // And not the other direction's documents, because the export
            // somebody wants is the list they are looking at.
            ->and(substr_count($csv, 'payable'))->toBeGreaterThan(0);
    });
});

<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * The two ways an invoice's figures went wrong, and the arithmetic that stops
 * them.
 *
 * Both were found on one screen: a customer's portal saying ₱21,482 had been
 * paid on two hauls quoted at ₱7,530 and ₱9,595. Neither figure was a rounding
 * slip — they were two different mistakes compounding.
 *
 * **VAT applied twice.** A write's `amount_cents` means the taxable base; a
 * read's means net plus VAT. The edit form read the second and posted it back
 * as the first, so re-saving an invoice put 12% on a figure that already had
 * 12% in it: ₱7,530 → ₱8,433.60 → ₱9,445.63, and 1.12² is exactly the 1.2544
 * the screen was out by.
 *
 * **Money counted from a status.** "Already paid" summed the invoices whose
 * status said `paid` rather than the payments received, so two documents
 * flagged by hand with nothing behind them read as ₱21,482 collected while the
 * bank had seen nothing — and as nothing owed.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::where('name', 'Negros Fresh Mart')->firstOrFail();

    // Not a firm that withholds, so the arithmetic under test is the VAT alone.
    $this->customer->update(['withholds_tax' => false]);

    $this->raise = fn (int $baseCents) => $this->actingAs($this->admin)->postJson('/api/v1/billing', [
        'direction' => 'receivable',
        'customer_id' => $this->customer->id,
        'amount_cents' => $baseCents,
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(30)->toDateString(),
    ])->assertCreated()->json('data');
});

describe('VAT is added once', function (): void {
    it('quotes a new invoice from the figure the desk typed', function (): void {
        $invoice = ($this->raise)(753000);

        // ₱7,530 net, 12% VAT, ₱8,433.60 on the document.
        expect($invoice['net_amount_cents'])->toBe(753000)
            ->and($invoice['vat_cents'])->toBe(90360)
            ->and($invoice['amount_cents'])->toBe(843360)
            // The figure a form edits — the base, not the gross. A client that
            // round-trips `amount_cents` re-taxes the tax.
            ->and($invoice['taxable_base_cents'])->toBe(753000);
    });

    it('leaves the figures alone when an edit changes nothing else', function (): void {
        $invoice = ($this->raise)(753000);

        // What a form does on a save: send back the base it was given.
        $saved = $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", [
                'amount_cents' => $invoice['taxable_base_cents'],
            ])
            ->assertOk()
            ->json('data');

        expect($saved['net_amount_cents'])->toBe(753000)
            ->and($saved['vat_cents'])->toBe(90360)
            ->and($saved['amount_cents'])->toBe(843360);
    });

    it('does not compound over repeated saves', function (): void {
        $invoice = ($this->raise)(753000);

        // Three saves. This is the case that was billing ₱9,445.63 for a
        // ₱7,530 haul: each one re-taxed the previous gross.
        foreach (range(1, 3) as $ignored) {
            $invoice = $this->actingAs($this->admin)
                ->patchJson("/api/v1/billing/{$invoice['id']}", [
                    'amount_cents' => $invoice['taxable_base_cents'],
                ])
                ->assertOk()
                ->json('data');
        }

        expect($invoice['amount_cents'])->toBe(843360);
    });

    it('re-quotes when the money actually moves', function (): void {
        $invoice = ($this->raise)(753000);

        // A real correction: the haul was ₱8,000, not ₱7,530.
        $corrected = $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", ['amount_cents' => 800000])
            ->assertOk()
            ->json('data');

        expect($corrected['net_amount_cents'])->toBe(800000)
            ->and($corrected['vat_cents'])->toBe(96000)
            ->and($corrected['amount_cents'])->toBe(896000);
    });

    it('leaves the tax untouched when only a date is corrected', function (): void {
        $invoice = ($this->raise)(753000);

        $moved = $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", [
                'due_at' => now()->addDays(45)->toDateString(),
            ])
            ->assertOk()
            ->json('data');

        // The rates may have changed since it was issued, and a document
        // already in a customer's hands must not quietly restate itself.
        expect($moved['amount_cents'])->toBe(843360)
            ->and($moved['vat_cents'])->toBe(90360);
    });
});

describe('money is counted from the payments', function (): void {
    it('will not let a status claim money that has not arrived', function (): void {
        $invoice = ($this->raise)(753000);

        // The route into the state on that screen: a form writing `paid`
        // straight onto the document.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", ['status' => StatusValue::Paid->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        expect(Invoice::findOrFail($invoice['id'])->status)->toBe(StatusValue::Pending);
    });

    it('reports what the customer has actually paid, not what is flagged', function (): void {
        $invoice = ($this->raise)(753000);

        // Flagged paid behind the API's back — the state the live data was in,
        // whatever put it there.
        Invoice::findOrFail($invoice['id'])->forceFill([
            'status' => StatusValue::Paid->value,
            'paid_at' => now(),
        ])->save();

        expect($this->customer->paidCents())->toBe(0)
            // And it is still owed, because nobody has paid it. The
            // status-based version read this as nothing outstanding.
            ->and($this->customer->outstandingCents())->toBe(843360);
    });

    it('counts a payment when there is one', function (): void {
        $invoice = ($this->raise)(753000);

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 843360,
            'paid_on' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'BDO-90210',
            'allocations' => [['invoice_id' => $invoice['id'], 'amount_cents' => 843360]],
        ])->assertCreated();

        expect($this->customer->paidCents())->toBe(843360)
            ->and($this->customer->outstandingCents())->toBe(0)
            // And *this* is how an invoice becomes paid: the status follows the
            // money rather than being typed on a form.
            ->and(Invoice::findOrFail($invoice['id'])->status)->toBe(StatusValue::Paid);
    });

    it('settles in one press, and leaves the payment behind it', function (): void {
        $invoice = ($this->raise)(753000);

        // The other way in — `POST billing/{invoice}/settle`, which is the
        // one-click case. It exists so nobody needs a status field for it, and
        // it records a real payment for the balance.
        $settled = $this->actingAs($this->admin)
            ->postJson("/api/v1/billing/{$invoice['id']}/settle", [
                'method' => 'cash',
                'reference' => 'CR-8891',
            ])
            ->assertOk()
            ->json('data');

        expect($settled['status'])->toBe(StatusValue::Paid->value)
            ->and($settled['paid_cents'])->toBe(843360)
            ->and($settled['balance_cents'])->toBe(0)
            ->and($this->customer->paidCents())->toBe(843360);
    });

    it('sees a part payment as part paid', function (): void {
        $invoice = ($this->raise)(753000);

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 300000,
            'paid_on' => now()->toDateString(),
            'method' => 'cash',
            'allocations' => [['invoice_id' => $invoice['id'], 'amount_cents' => 300000]],
        ])->assertCreated();

        // ₱3,000 in, ₱5,433.60 to go. The status-based figures could express
        // neither: the document was either wholly owed or wholly settled.
        expect($this->customer->paidCents())->toBe(300000)
            ->and($this->customer->outstandingCents())->toBe(543360);
    });

    it('shows the customer the same two figures', function (): void {
        $invoice = ($this->raise)(753000);

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 300000,
            'paid_on' => now()->toDateString(),
            'method' => 'cash',
            'allocations' => [['invoice_id' => $invoice['id'], 'amount_cents' => 300000]],
        ])->assertCreated();

        $buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

        $summary = $this->actingAs($buyer)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->json('data');

        // The two headline figures on the customer's home screen, and they add
        // up to the document.
        expect($summary['successful_payment_cents'])->toBe(300000)
            ->and($summary['pending_payment_cents'])->toBe(543360);
    });
});

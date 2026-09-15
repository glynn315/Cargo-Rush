<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;

/**
 * Everything a printable delivery invoice has to say, in one payload.
 *
 * The list and the detail endpoints answer a screen: a number, a customer, a
 * total, a status. A document is a different thing — it leaves the building. It
 * is what a client's accounts department files, what a collector carries, and
 * what somebody may have to read back in two years to settle an argument. So it
 * has to carry the things a screen can leave implicit:
 *
 *   **Who is billing.** The haulier's registered name, TIN, address and phone,
 *   and their mark. A document with no issuer on it is not an invoice, it is a
 *   figure.
 *
 *   **Who is being billed**, with their TIN and their VAT treatment, because
 *   those decide the tax lines and a Philippine invoice is expected to show
 *   both parties' TINs.
 *
 *   **What the money is for.** For a freight invoice that is the haul: the
 *   reference, the route, the load, the weight, and when it moved. This is what
 *   makes it a *delivery* invoice rather than a demand for money.
 *
 *   **What has been paid against it**, line by line — date, method, reference,
 *   amount — and what is left. An invoice reprinted after a part payment that
 *   still asks for the full amount is the document that starts the argument.
 *
 * ## Why it is assembled here rather than in the client
 *
 * All of it is already in the API, spread over four endpoints: the invoice, the
 * company, the customer and the trip. A client that stitched them together
 * would be four round trips, four chances to render a half-built document, and
 * a second place that decides what an invoice must contain. One endpoint, one
 * shape, and the same payload behind a print view, a PDF or an emailed copy
 * whenever those arrive.
 *
 * Nothing here is formatted. Money stays in centavos and dates stay ISO, as
 * everywhere else (DESIGN.md section 7.1) — except `amount_in_words`, which is
 * not formatting but a legal nicety of the document itself: a figure written
 * out cannot be altered by a pen stroke, which is the whole reason invoices
 * carry it.
 */
class InvoiceDocumentService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'customer',
            'trip.driver:id,name',
            'trip.vehicle:id,plate',
            'trip.customer:id,name',
            // The day the load actually arrived lives on the delivery log, not
            // on the trip — the trip knows when it was *meant* to move.
            'trip.deliveryLog:id,trip_id,delivered_at',
            'allocations.payment.recordedBy:id,name',
        ]);

        $company = $this->tenant->company();

        return [
            /**
             * The haulier. Read off the company in force rather than stored on
             * the invoice, and that is a real trade: a firm that changes its
             * address will reprint an old invoice with the new one. The
             * alternative is copying six fields onto every invoice row, which
             * would freeze a typo forever and is a migration this system has
             * not needed yet. What *is* frozen is everything that changes the
             * money — the tax rates and the treatment, which live on the
             * invoice.
             */
            'issuer' => [
                'name' => $company?->name,
                'code' => $company?->code,
                'tin' => $company?->tin,
                'vat_registered' => (bool) ($company?->vat_registered ?? false),
                'address' => $company?->address,
                'contact_name' => $company?->contact_name,
                'contact_phone' => $company?->contact_phone,
                'contact_email' => $company?->contact_email,
                // The mark the clients print at the top. Null means render the
                // company's initials, which is the same fallback the shell's
                // user chip already makes.
                'logo_url' => $this->logos->url($company?->logo_path),
            ],

            /**
             * Who is being billed.
             *
             * `counterparty()` because the same document type points both ways:
             * a receivable names a customer and a payable names a payee, and one
             * field means "the other party" in both cases.
             */
            'bill_to' => [
                'name' => $invoice->counterparty(),
                'customer_id' => $invoice->customer_id,
                'address' => $invoice->customer?->address,
                'contact' => $invoice->customer?->contact,
                'tin' => $invoice->customer?->tin,
                'vat_treatment' => $invoice->customer?->vat_treatment?->value,
                'vat_treatment_label' => $invoice->customer?->vat_treatment?->label(),
                'withholds_tax' => (bool) ($invoice->customer?->withholds_tax ?? false),
            ],

            'document' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                /**
                 * What to call it at the top of the page.
                 *
                 * "Delivery Invoice" when it was raised by a haul, because that
                 * is what the document is: a bill for a delivery, with the
                 * delivery on it. A manual receivable with no trip behind it is
                 * a plain sales invoice, and a payable is a bill somebody sent
                 * *us* — printing "Delivery Invoice" over that would be wrong
                 * in a way an accounts department would notice.
                 */
                'title' => $this->title($invoice),
                'direction' => $invoice->direction->value,
                'issued_at' => $invoice->issued_at?->toDateString(),
                'due_at' => $invoice->due_at?->toDateString(),
                /**
                 * The credit terms, worked out rather than stored: an invoice
                 * issued on the 1st and due on the 31st is thirty days,
                 * whatever anybody typed. Null when either date is missing —
                 * "0 days" would read as due on issue, which is a different
                 * arrangement.
                 */
                'terms_days' => $invoice->issued_at && $invoice->due_at
                    ? $invoice->issued_at->diffInDays($invoice->due_at)
                    : null,
                'status' => $invoice->status->value,
                'overdue' => $invoice->isOverdue(),
                'currency' => $invoice->currency,
            ],

            /**
             * The haul this is for — the line items of a freight invoice.
             *
             * A list of one, and deliberately shaped as a list: an invoice
             * covering a week of runs for one customer is the obvious next
             * feature, and a client that renders one row from an array will
             * render five without being rewritten.
             */
            'lines' => $this->lines($invoice),

            /**
             * The five figures, and the two rates that produced them.
             *
             *     net + vat = amount, and amount - withholding = due
             *
             * `due_cents` is what the bank should receive: a customer who
             * withholds pays less than the document asks, on purpose, and
             * remits the difference to the BIR on our behalf. An invoice that
             * printed only the gross would be read as unpaid forever.
             */
            'totals' => [
                'net_amount_cents' => $invoice->net_amount_cents,
                'vat_cents' => $invoice->vat_cents,
                'amount_cents' => $invoice->amount_cents,
                'withholding_cents' => $invoice->withholding_cents,
                'due_cents' => $invoice->dueCents(),
                'paid_cents' => $invoice->paidCents(),
                'balance_cents' => $invoice->balanceCents(),
                'vat_rate_bp' => $invoice->vat_rate_bp,
                'withholding_rate_bp' => $invoice->withholding_rate_bp,
                'vat_treatment' => $invoice->vat_treatment?->value,
                'vat_label' => $invoice->vat_treatment?->label(),
                /**
                 * The amount written out, as an invoice carries it.
                 *
                 * Not decoration and not formatting: a figure in words cannot
                 * be turned from 24,000 into 124,000 with a pen, which is why
                 * every printed invoice and cheque has had one for a century.
                 * It is the *due* figure — what the payer is actually being
                 * asked for.
                 */
                'amount_in_words' => Money::inWords($invoice->dueCents(), $invoice->currency),
            ],

            /**
             * What has arrived against this document, in order.
             *
             * The allocation rather than the payment total, because one payment
             * can settle three invoices: what belongs on this document is the
             * part of it that was put here. A collector reading this needs to
             * see the ₱20,000 that landed on this invoice, not the ₱60,000
             * cheque it came out of.
             */
            'payments' => $this->payments($invoice),
        ];
    }

    private function title(Invoice $invoice): string
    {
        if ($invoice->direction->value === 'payable') {
            return 'Bill';
        }

        return $invoice->trip_id === null ? 'Sales Invoice' : 'Delivery Invoice';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(Invoice $invoice): array
    {
        $trip = $invoice->trip;

        if ($trip === null) {
            // A manual invoice has no haul behind it. One line saying what the
            // document says, rather than an empty table: a printed invoice with
            // no description on it is a figure nobody can approve.
            return [[
                'description' => 'Freight and hauling services',
                'reference' => null,
                'origin' => null,
                'destination' => null,
                'cargo' => null,
                'weight_kg' => null,
                'delivered_at' => null,
                'driver' => null,
                'plate' => null,
                'amount_cents' => $invoice->net_amount_cents,
            ]];
        }

        return [[
            'description' => sprintf('Hauling: %s to %s', $trip->origin, $trip->destination),
            'reference' => $trip->reference,
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'cargo' => $trip->cargo,
            'weight_kg' => $trip->weight_kg,
            // The day the load actually arrived, which is the date a
            // customer's accounts department checks the invoice against. From
            // the delivery log, and falling back to the scheduled day for a run
            // invoiced before it was closed out.
            'delivered_at' => ($trip->deliveryLog?->delivered_at ?? $trip->scheduled_at)?->toDateString(),
            'driver' => $trip->driver?->name,
            'plate' => $trip->vehicle?->plate,
            // The net, because VAT is shown once at the bottom rather than
            // repeated on every line.
            'amount_cents' => $invoice->net_amount_cents,
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payments(Invoice $invoice): array
    {
        return $invoice->allocations
            ->sortBy(fn ($allocation) => (string) $allocation->payment?->paid_on)
            ->values()
            ->map(fn ($allocation): array => [
                'id' => $allocation->payment?->id,
                'paid_on' => $allocation->payment?->paid_on?->toDateString(),
                'method' => $allocation->payment?->method,
                'method_label' => $this->methodLabel($allocation->payment),
                'reference' => $allocation->payment?->reference,
                'notes' => $allocation->payment?->notes,
                'recorded_by' => $allocation->payment?->recordedBy?->name,
                // What of that payment landed on *this* invoice.
                'amount_cents' => (int) $allocation->amount_cents,
            ])
            ->all();
    }

    /**
     * `bank_transfer` as somebody would say it.
     *
     * The column is a free string rather than an enum, so this titles whatever
     * is in it instead of matching a list it would have to be kept in step
     * with — an install that starts recording "gcash" gets "Gcash" rather than
     * a blank.
     */
    private function methodLabel(?Payment $payment): ?string
    {
        $method = $payment?->method;

        return $method === null ? null : ucfirst(str_replace('_', ' ', $method));
    }
}

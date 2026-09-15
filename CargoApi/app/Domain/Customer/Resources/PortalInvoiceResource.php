<?php

declare(strict_types=1);

namespace App\Domain\Customer\Resources;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Resources\InvoiceResource;
use Illuminate\Http\Request;

/**
 * An invoice as the customer reads it — the document, plus who is owed.
 *
 * The same addition `PortalTripResource` makes, and for a sharper reason: a
 * customer with two hauliers has two sets of receivables, and "INV-2026-0440
 * is overdue" is not actionable until you know which office to pay.
 *
 * ## It also carries the liquidation
 *
 * The haul it is for, and every payment received against it. The office reads
 * an invoice as a row in a list it can cross-check against a ledger; a customer
 * reads one figure on a phone and has nothing to check it against, which is how
 * a wrong total goes unquestioned for a month. So this sends the workings: the
 * run, the date they asked for it, the net, the VAT, anything withheld, what
 * has been paid and when, and what is left. A customer who can add it up can
 * also tell you when it does not.
 *
 * @mixin Invoice
 */
class PortalInvoiceResource extends InvoiceResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'carrier_id' => $this->company_id,
            'carrier' => $this->company?->name,

            /**
             * The haul this document is for, and when they asked for it.
             *
             * `requested_at` is the trip's own scheduled day — the pickup the
             * customer asked for — falling back to when the request was filed
             * for one the desk has not scheduled. It is what the list is
             * ordered by, because a customer looks for "the Silway run" by
             * when they sent it, not by the day an office happened to raise
             * the paperwork.
             */
            'trip_reference' => $this->trip?->reference,
            'trip_origin' => $this->trip?->origin,
            'trip_destination' => $this->trip?->destination,
            'trip_cargo' => $this->trip?->cargo,
            'trip_weight_kg' => $this->trip?->weight_kg,
            'trip_status' => $this->trip?->status->value,
            'requested_at' => $this->iso($this->trip?->scheduled_at ?? $this->trip?->created_at ?? $this->issued_at),

            /**
             * Every payment against this document, oldest first.
             *
             * The allocated part rather than the whole payment: one transfer
             * can settle three invoices, and what belongs on this one is the
             * share that landed here. Without it the customer sees a balance
             * and has to take our word for how it got there.
             */
            'payments' => $this->allocations
                ->sortBy(fn (PaymentAllocation $allocation) => (string) $allocation->payment?->paid_on)
                ->values()
                ->map(fn (PaymentAllocation $allocation): array => [
                    'paid_on' => $allocation->payment?->paid_on?->toDateString(),
                    'method' => $allocation->payment?->method,
                    'method_label' => $this->methodLabel($allocation->payment?->method),
                    'reference' => $allocation->payment?->reference,
                    'amount_cents' => (int) $allocation->amount_cents,
                ])
                ->all(),
        ];
    }

    /**
     * `bank_transfer` as somebody would say it.
     *
     * The column is free text rather than an enum, so this titles whatever is
     * in it instead of matching a list it would have to be kept in step with.
     */
    private function methodLabel(?string $method): ?string
    {
        return $method === null ? null : ucfirst(str_replace('_', ' ', $method));
    }
}

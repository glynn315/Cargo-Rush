<?php

declare(strict_types=1);

namespace App\Domain\Billing\Resources;

use App\Domain\Billing\Models\Payment;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Payment
 */
class PaymentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,
            'direction' => $this->direction->value,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,

            // The day the money moved, not the day somebody typed it in. Every
            // collections figure is dated from this.
            'paid_on' => $this->paid_on?->toDateString(),
            'method' => $this->method,
            // What a bank statement is matched against.
            'reference' => $this->reference,
            'notes' => $this->notes,

            /**
             * Where it went, and what is left over.
             *
             * A payment larger than the invoices it was applied to is not an
             * error — a customer paying a round ₱100,000 against ₱97,340 of
             * bills has ₱2,660 of credit, which goes against whatever they are
             * billed next. Showing the gap is what lets somebody notice it.
             */
            'allocated_cents' => $this->allocatedCents(),
            'unallocated_cents' => $this->unallocatedCents(),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(
                fn ($allocation): array => [
                    'invoice_id' => $allocation->invoice_id,
                    'invoice_number' => $allocation->invoice?->number,
                    'amount_cents' => $allocation->amount_cents,
                ],
            )),

            'recorded_by' => $this->recordedBy?->name,

            ...$this->stamps(),
        ];
    }
}

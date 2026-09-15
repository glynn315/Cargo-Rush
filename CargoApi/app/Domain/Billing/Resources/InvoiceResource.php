<?php

declare(strict_types=1);

namespace App\Domain\Billing\Resources;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Invoice
 */
class InvoiceResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            // One column in the UI whichever way the money points.
            'customer' => $this->counterparty(),
            'customer_id' => $this->customer_id,
            'payee' => $this->payee,
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),

            /**
             * The five figures a Philippine freight invoice actually carries.
             *
             * `amount_cents` keeps its old meaning — what the document says,
             * net plus VAT — so every client written before tax existed still
             * reads the right total. The rest are what it was previously
             * silent about:
             *
             *     net + vat = amount, and amount - withholding = due
             *
             * `due_cents` is the one that matters at the bank. A customer who
             * withholds pays less than the document asks, deliberately, and
             * the difference is remitted to the BIR on our behalf rather than
             * owed to us. Reading a short payment as a shortfall was the
             * reconciliation error this removes.
             */
            'net_amount_cents' => $this->net_amount_cents,
            'vat_cents' => $this->vat_cents,
            'amount_cents' => $this->amount_cents,

            /**
             * What a form should edit, and what a write's `amount_cents` means.
             *
             * The net when the rate card is quoted excluding VAT, the gross
             * when it is quoted all-in — whichever the tax was worked out
             * from. A client that edits `amount_cents` instead is posting a
             * figure that already has VAT in it back as a taxable base, which
             * adds the VAT a second time on every save. See
             * `Invoice::taxBaseCents()`.
             */
            'taxable_base_cents' => $this->taxBaseCents(),
            'withholding_cents' => $this->withholding_cents,
            'due_cents' => $this->dueCents(),

            // How much has arrived, and what is left. Both summed from the
            // payments, never stored — a paid total that can disagree with the
            // payments beneath it is the thing this codebase refuses to keep.
            'paid_cents' => $this->paidCents(),
            'balance_cents' => $this->balanceCents(),

            // Frozen on the day. Shown so a document can be reprinted years
            // later exactly as it was issued, whatever the rates are by then.
            'vat_rate_bp' => $this->vat_rate_bp,
            'withholding_rate_bp' => $this->withholding_rate_bp,
            'vat_treatment' => $this->vat_treatment?->value,
            'vat_label' => $this->vat_treatment?->label(),

            'currency' => $this->currency,
            'trip_id' => $this->trip_id,
            // Printed beside the amount, so an invoice raised by a delivery
            // can be reconciled against the run without a human matching
            // dates and figures by eye.
            'trip_reference' => $this->trip?->reference,
            'direction' => $this->direction->value,
            'status' => $this->status->value,
            // When the money arrived, as against when the document was
            // issued. `updated_at` would move on any later correction.
            'paid_at' => $this->iso($this->paid_at),

            ...$this->stamps(),
        ];
    }
}

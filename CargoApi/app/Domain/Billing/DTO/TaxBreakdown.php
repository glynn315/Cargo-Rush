<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use App\Domain\Shared\Enums\VatTreatment;

/**
 * The four figures on an invoice, and the rates that produced them.
 *
 *     net           the haul itself, the taxable base
 *   + vat           charged on, remitted by us
 *   = gross         what the document says
 *   - withholding   kept back by the customer, remitted by them
 *   = due           what actually lands in the bank
 *
 * `gross` and `due` are derived here rather than stored, because they are
 * arithmetic on the three that are — and a stored total that can disagree with
 * its own parts is the failure this codebase avoids everywhere else. The
 * *rates* are carried because an invoice freezes them: what applied on the day
 * is part of the document, not a lookup that changes when statute does.
 */
final class TaxBreakdown
{
    public function __construct(
        public readonly int $net_cents,
        public readonly int $vat_cents,
        public readonly int $withholding_cents,
        public readonly int $vat_rate_bp,
        public readonly int $withholding_rate_bp,
        public readonly VatTreatment $treatment,
    ) {}

    /** What the invoice asks for: net plus VAT. */
    public function grossCents(): int
    {
        return $this->net_cents + $this->vat_cents;
    }

    /** What the payer actually remits, after keeping back the withholding. */
    public function dueCents(): int
    {
        return $this->grossCents() - $this->withholding_cents;
    }

    /**
     * The columns, ready to write onto an invoice.
     *
     * `amount_cents` is the gross and keeps its old meaning — what the
     * document says — so every reader written before tax existed still reads
     * the right figure.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'net_amount_cents' => $this->net_cents,
            'vat_cents' => $this->vat_cents,
            'amount_cents' => $this->grossCents(),
            'withholding_cents' => $this->withholding_cents,
            'vat_rate_bp' => $this->vat_rate_bp,
            'withholding_rate_bp' => $this->withholding_rate_bp,
            'vat_treatment' => $this->treatment->value,
        ];
    }
}

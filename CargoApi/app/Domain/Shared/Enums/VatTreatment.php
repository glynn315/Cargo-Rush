<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * How VAT applies to a customer.
 *
 * Three values rather than a boolean, because zero-rated and exempt both put
 * ₱0 on the invoice and are **not the same thing** to the filing. A zero-rated
 * sale is a VAT sale at 0% — an exporter, a PEZA locator — and the input VAT
 * behind it is still claimable. An exempt sale is outside the VAT system
 * entirely, and the input VAT behind it is not.
 *
 * Collapsing them would produce identical invoices and a wrong return, which
 * is the kind of error that surfaces at an audit rather than on a screen.
 */
enum VatTreatment: string
{
    /** The ordinary case: VAT is charged at the prevailing rate. */
    case Vatable = 'vatable';

    /** A VAT sale at 0%. Exports and PEZA locators. */
    case ZeroRated = 'zero_rated';

    /** Outside the VAT system. */
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Vatable => 'VAT',
            self::ZeroRated => 'Zero-rated',
            self::Exempt => 'VAT-exempt',
        };
    }

    /** Does this treatment put a VAT figure on the document? */
    public function charges(): bool
    {
        return $this === self::Vatable;
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

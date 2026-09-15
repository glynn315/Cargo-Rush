<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The shared status vocabulary — DESIGN.md section 1.
 *
 * The API returns the *string*; the clients map it to a colour. A hex value
 * must never cross the wire.
 */
enum StatusValue: string
{
    case Active = 'active';
    case Delivered = 'delivered';
    case Available = 'available';
    case InTransit = 'in_transit';
    case Assigned = 'assigned';
    case Scheduled = 'scheduled';
    case Pending = 'pending';
    case Maintenance = 'maintenance';
    case Cancelled = 'cancelled';
    case Overdue = 'overdue';
    case Inactive = 'inactive';
    /**
     * Paid in part, but not in full.
     *
     * A state the system could not express while settling an invoice was one
     * flip from `pending` to `paid`: ₱50,000 against a ₱120,000 document had
     * to be recorded as one or the other, and both were wrong — the first
     * loses the money, the second loses the debt.
     */
    case Partial = 'partial';
    /**
     * Money that has actually arrived.
     *
     * Settling an invoice used to write `delivered`, which is the word for a
     * closed-out haul and reads as nonsense on a receivable. Worse, it made
     * "paid" and "delivered" the same value, so no page could count collected
     * money without also counting every delivered trip's document. This is
     * that distinction, and it is why the Dashboard can now separate money
     * owed from money in.
     */
    case Paid = 'paid';

    /** The four tones the clients render pills in. */
    public function tone(): Tone
    {
        return match ($this) {
            self::Active, self::Delivered, self::Paid => Tone::Success,
            self::Available, self::InTransit, self::Assigned, self::Scheduled => Tone::Info,
            // Amber, like `pending`: money has arrived but the document is not
            // closed, and it still needs chasing.
            self::Pending, self::Maintenance, self::Partial => Tone::Warning,
            self::Cancelled, self::Overdue, self::Inactive => Tone::Danger,
        };
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

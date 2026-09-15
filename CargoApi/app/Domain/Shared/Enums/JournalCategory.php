<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The accounting category on a journal entry — *why* it was written.
 *
 * Not a second chart of accounts, and not a summary of the accounts an entry
 * touches. The accounts are on the lines, where they belong, and one ordinary
 * entry touches two or three of them across different types: a fuel purchase
 * on credit is an expense line and a payable line, and calling the transaction
 * either "expense" or "liability" would be picking one side and losing the
 * other.
 *
 * This is the answer to the question somebody scrolling a month of journal
 * entries is actually asking — what *kind* of thing is this? — and it is the
 * grouping the office thinks in: fuel, payroll, billing, collections. It gives
 * the journal a filter and a subtotal that survive a change to the chart of
 * accounts, because renumbering 5300 does not change the fact that a fill-up
 * is fuel.
 *
 * A closed list rather than a table the office edits, for one reason: these are
 * the categories the *system* will file its own automatic postings under when
 * an invoice or a day's sheet starts posting itself, so code has to be able to
 * name them. A category nobody could rely on would be no use to that. The free
 * grouping the office wants is `accounts.group`, which is theirs to define.
 */
enum JournalCategory: string
{
    /** The day's hauling — a run's income and the cost of doing it. */
    case Operations = 'operations';
    /** Wages, allowances and the deductions that come off them. */
    case Payroll = 'payroll';
    /** Diesel. Its own category because it is the fleet's largest single cost. */
    case Fuel = 'fuel';
    /** Parts, labour, tyres — keeping the units on the road. */
    case Maintenance = 'maintenance';
    /** Issuing a document: what a customer now owes for work done. */
    case Billing = 'billing';
    /** Money arriving, and what it settles. */
    case Collection = 'collection';
    /** Paying somebody else — suppliers, the yard, the office. */
    case Disbursement = 'disbursement';
    /** Loans, leases, capital in and drawings out. */
    case Financing = 'financing';
    /** VAT, withholding, and anything else the BIR is owed. */
    case Tax = 'tax';
    /**
     * A correction, an accrual, a depreciation run — the entries an accountant
     * writes because the books need them rather than because something
     * happened outside.
     */
    case Adjustment = 'adjustment';
    /**
     * The balances an install starts from.
     *
     * Its own category because it is the one entry that is not a transaction:
     * it is the state of the business on the day the books began here, and it
     * should never be mixed in with the month it was typed in.
     */
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Operations => 'Operations',
            self::Payroll => 'Payroll',
            self::Fuel => 'Fuel',
            self::Maintenance => 'Maintenance',
            self::Billing => 'Billing',
            self::Collection => 'Collection',
            self::Disbursement => 'Disbursement',
            self::Financing => 'Financing',
            self::Tax => 'Tax',
            self::Adjustment => 'Adjustment',
            self::Opening => 'Opening balance',
        };
    }

    /**
     * The icon the clients render it with — a name from the shared set, never a
     * colour (DESIGN.md section 7.1).
     *
     * Every one of these is a name both clients already ship. A category whose
     * icon does not exist renders as a gap, and a gap in a list of eleven rows
     * reads as a bug in the row rather than a missing file.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Operations => 'route',
            self::Payroll => 'badge',
            self::Fuel => 'fuel',
            self::Maintenance => 'fleet',
            self::Billing => 'billing',
            self::Collection => 'wallet',
            self::Disbursement => 'tag',
            self::Financing => 'trend',
            self::Tax => 'shield',
            self::Adjustment => 'clipboard',
            self::Opening => 'calendar',
        };
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}

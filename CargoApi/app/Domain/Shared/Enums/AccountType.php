<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The five kinds of account, and the three questions the answer decides.
 *
 * Every account in the chart is one of these, and the type is not a label. It
 * settles, for every posting that will ever touch that account:
 *
 *   **Which way a debit moves it.** A debit raises an asset and lowers a
 *   liability. That is not a convention this system is free to choose — it is
 *   what debit and credit *mean* — and `normalBalance()` is where it is stated
 *   once so that no report has to work it out again.
 *
 *   **Which statement it belongs to.** Assets, liabilities and equity make the
 *   balance sheet; income and expense make the income statement. The two are
 *   read over different periods (a balance sheet is a moment, an income
 *   statement is a range), which is why `isPermanent()` exists rather than
 *   every report knowing the list.
 *
 *   **Where it sits on a trial balance.** Debit-normal accounts on the left,
 *   credit-normal on the right, and the two columns agree or the books do not
 *   balance.
 *
 * The vocabulary is the standard one and deliberately not localised. A
 * Philippine haulier's accountant, their auditor and their BIR filings all use
 * these five words; inventing friendlier ones would help nobody who actually
 * has to reconcile this.
 */
enum AccountType: string
{
    /** What the business owns — cash, receivables, trucks. */
    case Asset = 'asset';
    /** What it owes — payables, loans, taxes withheld and not yet remitted. */
    case Liability = 'liability';
    /** What is left for the owners — capital, drawings, retained earnings. */
    case Equity = 'equity';
    /** What it earns — freight revenue, and the odd gain beside it. */
    case Income = 'income';
    /** What it costs to earn that — fuel, salaries, maintenance, the office. */
    case Expense = 'expense';

    /**
     * The side that *increases* this account.
     *
     * The one rule the whole ledger turns on. An asset or an expense grows on
     * the debit side; a liability, equity or income grows on the credit side.
     * So a balance is always `debits - credits` for the first pair and
     * `credits - debits` for the second, and `GeneralLedgerService` asks this
     * rather than carrying its own copy of the rule.
     */
    public function normalBalance(): BalanceSide
    {
        return match ($this) {
            self::Asset, self::Expense => BalanceSide::Debit,
            self::Liability, self::Equity, self::Income => BalanceSide::Credit,
        };
    }

    /**
     * Does this account carry over from one period to the next?
     *
     * True for the balance-sheet three: a truck the fleet owned in December is
     * a truck it owns in January. False for income and expense, which are the
     * story of *a* period and start again — which is what closing the books
     * means, and why a ledger for an expense account has no opening balance
     * from before the range being asked about.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Income, self::Expense => false,
        };
    }

    /** What the clients print above the group. */
    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Assets',
            self::Liability => 'Liabilities',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expenses',
        };
    }

    /**
     * The order a chart of accounts is always printed in.
     *
     * Not alphabetical, and not the order somebody added them: assets,
     * liabilities, equity, income, expenses is the sequence every accountant
     * reads a chart in, and matching it means nobody has to hunt.
     */
    public function position(): int
    {
        return match ($this) {
            self::Asset => 1,
            self::Liability => 2,
            self::Equity => 3,
            self::Income => 4,
            self::Expense => 5,
        };
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

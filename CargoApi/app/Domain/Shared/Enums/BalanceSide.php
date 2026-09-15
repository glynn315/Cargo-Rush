<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Debit or credit. The two sides every posting has one of.
 *
 * Small enough to look unnecessary and worth having anyway: without it,
 * "which side does this account grow on" is answered by a boolean somewhere,
 * and a boolean called `$isDebit` reads the wrong way round exactly once and
 * then the trial balance is inverted. `AccountType::normalBalance()` returns
 * this, and the ledger's arithmetic is written in terms of it.
 *
 * Left and right are not a metaphor: on a printed trial balance, debits are the
 * left-hand column and credits the right, and the two columns agreeing is the
 * whole check.
 */
enum BalanceSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /** The other one. A reversal is every line with its side flipped. */
    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    /**
     * The sign this side contributes to a balance held in this account's own
     * normal direction.
     *
     * So a debit-normal account (an asset, an expense) counts debits `+1` and
     * credits `-1`, and a credit-normal one counts them the other way. Written
     * once here rather than as a `match` in each report.
     */
    public function sign(self $movement): int
    {
        return $this === $movement ? 1 : -1;
    }

    public function label(): string
    {
        return $this === self::Debit ? 'Debit' : 'Credit';
    }
}

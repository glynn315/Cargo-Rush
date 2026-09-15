<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Which cutoff the monthly contributions come off.
 *
 * SSS, PhilHealth and Pag-IBIG are **monthly** figures, and payroll here runs
 * twice a month. Something has to decide how a monthly figure lands on two
 * payslips, and offices genuinely differ: some halve each contribution across
 * both cutoffs, and plenty take the whole month's contributions off one of them
 * — usually the second, so the first payslip of the month is the fuller one.
 *
 * All three answers remit the same amount to the same agency at the end of the
 * month. What changes is which payslip is lighter, which is why this is the
 * firm's decision and not a rate: there is no right answer to find, only a
 * policy to record. It lives on the company row beside `vat_rate_bp` for the
 * same reason that does.
 *
 * The withholding tax deliberately does **not** follow this setting. It is
 * charged on what is left after the contributions actually taken on that
 * cutoff, which is the order the BIR computes it in — so moving the
 * contributions onto one cutoff moves the tax with them, as it should.
 */
enum DeductionSchedule: string
{
    /** Half of each contribution on each cutoff. */
    case Split = 'split';

    /** The whole month's contributions on the 1st-to-15th payslip. */
    case FirstCutoff = 'first';

    /** The whole month's contributions on the 16th-to-end payslip. */
    case SecondCutoff = 'second';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Split => 'Split across both cutoffs',
            self::FirstCutoff => 'All on the first cutoff (1st–15th)',
            self::SecondCutoff => 'All on the second cutoff (16th–end of month)',
        };
    }

    /** What this policy means on a payslip, for somebody reading the screen. */
    public function detail(): string
    {
        return match ($this) {
            self::Split => 'Half of each monthly contribution comes off each payslip.',
            self::FirstCutoff => 'The whole month of SSS, PhilHealth and Pag-IBIG comes off the first payslip. The second carries none.',
            self::SecondCutoff => 'The whole month of SSS, PhilHealth and Pag-IBIG comes off the second payslip. The first carries none.',
        };
    }

    /**
     * How much of a monthly contribution one cutoff carries, in centavos.
     *
     * `$isFirstCutoff` rather than a pay period, so this stays a piece of
     * vocabulary and does not grow a dependency on payroll's calendar. The
     * caller knows which half it is building.
     *
     * `$isOnlyRun` is the monthly-payroll case: there is no second payslip for
     * the rest to land on, so the whole contribution comes off whatever the
     * policy says. A firm that pays once a month has no cutoff to choose
     * between.
     *
     * On `Split`, the odd centavo goes to the **second** cutoff, so the two
     * halves add up to the month exactly. Halving twice with `intdiv` and
     * hoping is how a firm under-remits a peso a year per employee, which is
     * small, permanent and impossible to explain.
     */
    public function shareOf(int $monthlyCents, bool $isFirstCutoff, bool $isOnlyRun = false): int
    {
        if ($isOnlyRun) {
            return $monthlyCents;
        }

        return match ($this) {
            self::Split => $isFirstCutoff
                ? intdiv($monthlyCents, 2)
                : $monthlyCents - intdiv($monthlyCents, 2),
            self::FirstCutoff => $isFirstCutoff ? $monthlyCents : 0,
            self::SecondCutoff => $isFirstCutoff ? 0 : $monthlyCents,
        };
    }

    /** Does this cutoff carry the contributions at all? */
    public function carriedOn(bool $isFirstCutoff, bool $isOnlyRun = false): bool
    {
        return $isOnlyRun || $this->shareOf(100, $isFirstCutoff) > 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use Illuminate\Support\Carbon;

/**
 * A pay period: the 1st to the 15th, or the 16th to the end of the month.
 *
 * Payroll is **cut off on the 1st and the 16th**, which is the Philippine
 * semi-monthly norm, so a period is not an arbitrary range somebody types. It
 * is one of two per month, and this class is the only place that knows which
 * two — the validator asks it, the endpoint that offers the choice is built
 * from it, and the client renders whatever it is handed rather than doing
 * calendar arithmetic of its own.
 *
 * ## Why the shape is enforced rather than merely defaulted
 *
 * Statutory contributions are monthly figures split across the month's runs
 * (see `StatutoryDeductions`), and the withholding table is the BIR's
 * **semi-monthly** one. Both are only correct if a run really is half a month.
 * A run covering the 3rd to the 20th would take half a month's SSS off
 * eighteen days of pay and tax it on a table built for fifteen — wrong twice,
 * and wrong invisibly.
 *
 * ## What it costs
 *
 * A period that is not a half-month cannot be opened at all: no 13th-month
 * run, no final-pay run for somebody leaving mid-period, no one-off. Those are
 * real things an office eventually needs, and the way to do them here is an
 * adjustment on the next run's payslip, or a journal entry. That is a
 * deliberate trade for figures that are right by construction.
 *
 * ## Monthly payrolls
 *
 * `cargo.payroll.runs_per_month` already decides how a monthly contribution is
 * split. Set it to 1 and the only legal period becomes the whole month, so the
 * rule here and the arithmetic there cannot disagree.
 */
final class PayPeriod
{
    public const FIRST_HALF = 'first';

    public const SECOND_HALF = 'second';

    public const WHOLE_MONTH = 'month';

    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $half,
    ) {}

    /** The 1st to the 15th. */
    public static function firstHalf(int $year, int $month): self
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfDay();

        return new self($start, $start->copy()->day(15), self::FIRST_HALF);
    }

    /**
     * The 16th to the last day of the month.
     *
     * Fourteen days in a non-leap February and sixteen in July, which is why
     * the end is read off the calendar rather than written as a number.
     */
    public static function secondHalf(int $year, int $month): self
    {
        $start = Carbon::createFromDate($year, $month, 16)->startOfDay();

        return new self($start, $start->copy()->endOfMonth()->startOfDay(), self::SECOND_HALF);
    }

    /** The whole month — the only legal period where payroll runs once. */
    public static function wholeMonth(int $year, int $month): self
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfDay();

        return new self($start, $start->copy()->endOfMonth()->startOfDay(), self::WHOLE_MONTH);
    }

    /**
     * Every legal period in one month, in the order they are worked.
     *
     * @return array<int, self>
     */
    public static function inMonth(int $year, int $month): array
    {
        return self::runsPerMonth() === 1
            ? [self::wholeMonth($year, $month)]
            : [self::firstHalf($year, $month), self::secondHalf($year, $month)];
    }

    /**
     * The legal period these two dates are, or null if they are not one.
     *
     * The month is taken from the start date, so a range that crosses a month
     * boundary matches nothing — which is the answer, since a period never
     * does.
     */
    public static function matching(Carbon $start, Carbon $end): ?self
    {
        foreach (self::inMonth((int) $start->year, (int) $start->month) as $period) {
            if ($period->start->isSameDay($start) && $period->end->isSameDay($end)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * The period that has just closed — the one an office opens payroll for.
     *
     * On the 16th or later, the first half of this month is done. Before that,
     * the run waiting to be paid is the back half of last month. Which is to
     * say: whatever the last cutoff to have passed was.
     */
    public static function justClosed(?Carbon $now = null): self
    {
        $now ??= Carbon::now();

        if (self::runsPerMonth() === 1) {
            $previous = $now->copy()->subMonthNoOverflow();

            return self::wholeMonth((int) $previous->year, (int) $previous->month);
        }

        if ((int) $now->day >= 16) {
            return self::firstHalf((int) $now->year, (int) $now->month);
        }

        $previous = $now->copy()->subMonthNoOverflow();

        return self::secondHalf((int) $previous->year, (int) $previous->month);
    }

    /**
     * Which cutoff a run's dates are, for splitting a monthly figure.
     *
     * `first` decides which half of the salary and which share of the
     * contributions this payslip carries; `only` says there is no second
     * payslip for the rest to land on, which is the monthly-payroll case.
     *
     * A run whose dates are not a recognised period — one opened before the
     * cutoff rule existed — is classified by its start day rather than
     * refused. This is arithmetic on a run that already exists, and the honest
     * answer for a period starting on the 3rd is "the first half of the month".
     *
     * @return array{first: bool, only: bool}
     */
    public static function classify(Carbon $start, Carbon $end): array
    {
        $period = self::matching($start, $end);

        if ($period !== null) {
            return [
                'first' => $period->half !== self::SECOND_HALF,
                'only' => $period->half === self::WHOLE_MONTH,
            ];
        }

        return ['first' => (int) $start->day <= 15, 'only' => self::runsPerMonth() === 1];
    }

    /**
     * The day the period closes and payroll is run: the 16th, or the 1st.
     *
     * The day after the last day worked, which is what a cutoff is. Offered as
     * the default pay date and no more than that — when the money actually
     * leaves the bank is the office's decision, and plenty pay on the 20th.
     */
    public function cutoff(): Carbon
    {
        return $this->end->copy()->addDay();
    }

    /** Days in the period, counting both ends. */
    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** `1–15 Sep 2026` — the same shape as `PayRun::periodLabel()`. */
    public function label(): string
    {
        return $this->start->format('j').'–'.$this->end->format('j M Y');
    }

    /** `1–15` — for a two-button choice where the month is already on screen. */
    public function short(): string
    {
        return $this->start->format('j').'–'.$this->end->format('j');
    }

    public function matches(self $other): bool
    {
        return $this->start->isSameDay($other->start) && $this->end->isSameDay($other->end);
    }

    /**
     * Why a range was refused, naming the periods it should have been.
     *
     * The message does the teaching, because the reader is somebody who typed
     * a sensible-looking fortnight and got a 422: it has to say what the rule
     * is and what the two right answers are for the month they were aiming at.
     */
    public static function explainFor(Carbon $start): string
    {
        $periods = self::inMonth((int) $start->year, (int) $start->month);

        if (self::runsPerMonth() === 1) {
            return sprintf(
                'Payroll runs once a month here, so a pay period is the whole month — %s. Choose that.',
                $periods[0]->label(),
            );
        }

        return sprintf(
            'Payroll is cut off on the 1st and the 16th, so a pay period runs %s or %s. Choose one of those.',
            $periods[0]->label(),
            $periods[1]->label(),
        );
    }

    /** How many runs a month, from configuration. See the class note. */
    public static function runsPerMonth(): int
    {
        return max(1, (int) config('cargo.payroll.runs_per_month', 2));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'half' => $this->half,
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'label' => $this->label(),
            'short' => $this->short(),
            /** The day the period closes: the 16th, or the 1st of next month. */
            'cutoff' => $this->cutoff()->toDateString(),
            'days' => $this->days(),
        ];
    }
}

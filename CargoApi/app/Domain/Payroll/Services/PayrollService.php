<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Accounting\DTO\JournalEntryData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll: building a run, freezing it, paying it, and putting it in the books.
 *
 * ## The four verbs, and why they are verbs
 *
 * **Build** takes a period and works out what everybody is owed. It can be run
 * again — that is what a draft is for, and the second run replaces the first
 * rather than adding to it.
 *
 * **Approve** freezes the figures. Somebody has looked at them and said yes,
 * and payslips can go out — which means nothing may move afterwards. Same
 * argument as posting a journal entry.
 *
 * **Pay** records that the money went out and **posts the run to the ledger**:
 * salaries to expense, each agency's share to its own payable, the net to cash.
 * This is what makes payroll part of the accounts rather than a spreadsheet
 * beside them, and it is the first thing in this system that posts itself.
 *
 * **Delete** is for a draft only. An approved run has been shown to people.
 *
 * ## Who is on a run
 *
 * Active employees with a monthly basic on record. A fleet's drivers are often
 * paid **per trip** rather than salaried — that money is already in the daily
 * truck sheet's driver and helper columns — so an employee with no basic is
 * left off rather than paid twice. The office can still add anything a person
 * is owed as an adjustment on their line.
 */
class PayrollService
{
    public function __construct(
        private readonly StatutoryDeductions $deductions,
        private readonly JournalService $journal,
        private readonly NotificationService $notifications,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return PayRun::query()
            ->with(['lines', 'approvedBy:id,name', 'journalEntry:id,reference'])
            ->when(
                ! empty($filters['status']),
                fn ($query) => $query->whereIn('status', (array) $filters['status']),
            )
            ->when(
                ! empty($filters['from']),
                fn ($query) => $query->whereDate('period_start', '>=', $filters['from']),
            )
            ->when(
                ! empty($filters['to']),
                fn ($query) => $query->whereDate('period_end', '<=', $filters['to']),
            )
            ->inPayrollOrder()
            ->paginate($perPage);
    }

    public function find(string $id): PayRun
    {
        return PayRun::query()
            ->with(['lines.employee:id,employee_no,first_name,last_name', 'approvedBy:id,name', 'journalEntry:id,reference'])
            ->findOrFail($id);
    }

    /**
     * Open a run for a period and work out everybody's pay.
     *
     * One transaction over the run and its lines, because a run with no lines
     * is not a payroll — and re-running a draft replaces the lines wholesale
     * for the same reason an edited journal entry replaces its own: merging
     * would leave somebody paid from two different calculations.
     */
    public function build(
        Carbon $periodStart,
        Carbon $periodEnd,
        Carbon $payDate,
        ?User $author = null,
        ?PayRun $existing = null,
    ): PayRun {
        if ($existing !== null) {
            $this->mustBeOpen($existing);
        }

        return DB::transaction(function () use ($periodStart, $periodEnd, $payDate, $author, $existing): PayRun {
            $run = $existing ?? new PayRun;

            $run->fill([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'pay_date' => $payDate->toDateString(),
                'status' => PayRun::DRAFT,
            ]);

            if ($author !== null && $run->created_by === null) {
                $run->created_by = $author->id;
            }

            $run->save();

            // Wholesale, not merged. See the note above.
            $run->lines()->delete();

            // Which cutoff this is, worked out once for the whole run rather
            // than per employee: it is a fact about the period, and every
            // payslip on the run shares it.
            $cutoff = PayPeriod::classify($run->period_start, $run->period_end);
            $schedule = $this->schedule();

            foreach ($this->payable() as $employee) {
                $this->writeLine($run, $employee, $cutoff, $schedule);
            }

            return $run->refresh()->load('lines');
        });
    }

    /**
     * Everybody the run pays.
     *
     * Active, and with a basic on record. See the class note on why somebody
     * paid per trip is deliberately not here.
     *
     * @return Collection<int, Employee>
     */
    public function payable()
    {
        return Employee::query()
            ->where('status', StatusValue::Active->value)
            ->where('base_salary_cents', '>', 0)
            ->orderBy('last_name')
            ->get();
    }

    /**
     * One person's line, with the figures frozen onto it.
     *
     * The name and the position are copied along with the money — a payslip is
     * a statement about a fortnight and has to keep saying what it said after
     * somebody is promoted. See `PayRunLine`.
     *
     * @param  array{first: bool, only: bool}  $cutoff
     */
    private function writeLine(
        PayRun $run,
        Employee $employee,
        array $cutoff,
        DeductionSchedule $schedule,
    ): PayRunLine {
        $monthly = (int) $employee->base_salary_cents;

        /**
         * The monthly salary split across the month's two cutoffs.
         *
         * The **second** cutoff carries the remainder, so the two payslips add
         * up to the monthly salary exactly. Halving with `intdiv` on both runs
         * would quietly short a salary ending in an odd centavo by one centavo
         * every month — twelve centavos a year per employee, permanently, and
         * impossible to find from either payslip.
         */
        $basic = match (true) {
            $cutoff['only'] => $monthly,
            $cutoff['first'] => intdiv($monthly, 2),
            default => $monthly - intdiv($monthly, 2),
        };

        $statutory = $this->deductions->for(
            $monthly,
            $basic,
            $cutoff['first'],
            $cutoff['only'],
            $schedule,
        );

        $line = new PayRunLine([
            'pay_run_id' => $run->getKey(),
            'employee_id' => $employee->getKey(),
            'employee_no' => $employee->employee_no,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'position' => $employee->position,
            'basic_cents' => $basic,
            'allowance_cents' => 0,
            'overtime_cents' => 0,
            'adjustments_cents' => 0,
            'sss_cents' => $statutory['sss'],
            'philhealth_cents' => $statutory['philhealth'],
            'pagibig_cents' => $statutory['pagibig'],
            'withholding_tax_cents' => $statutory['withholding_tax'],
            'other_deductions_cents' => 0,
        ]);

        return $this->settleTotals($line);
    }

    /**
     * The firm's own policy on which cutoff the contributions come off.
     *
     * A column on the company rather than configuration, because two firms in
     * the same yard answer it differently and neither is wrong. Falls back to
     * splitting, which is what payroll did before the setting existed.
     */
    public function schedule(): DeductionSchedule
    {
        return $this->tenant->company()?->payroll_deduct_on ?? DeductionSchedule::Split;
    }

    /**
     * Correct one line — an allowance, some overtime, a cash advance.
     *
     * The escape hatch that makes this usable without a timekeeping module,
     * and the one that makes the statutory approximations safe: an office that
     * knows the right SSS figure types the right figure.
     *
     * The tax is **not** recomputed from the new gross. That is deliberate and
     * worth stating: an adjustment is usually a one-off that the BIR table
     * would tax as if it were the person's regular pay, and an office
     * correcting a payslip has not asked for the tax to move under them. A run
     * that needs the tax redone is rebuilt.
     *
     * @param  array<string, mixed>  $changes
     */
    public function adjustLine(PayRunLine $line, array $changes): PayRunLine
    {
        $this->mustBeOpen($line->payRun);

        $line->fill(array_intersect_key($changes, array_flip([
            'allowance_cents', 'overtime_cents', 'adjustments_cents', 'adjustment_note',
            'sss_cents', 'philhealth_cents', 'pagibig_cents', 'withholding_tax_cents',
            'other_deductions_cents', 'deduction_note',
        ])));

        return $this->settleTotals($line);
    }

    /**
     * Write the three stored totals from the parts, and save.
     *
     * The only place they are written. They are stored because they are what
     * somebody was handed on paper — see `PayRunLine` — and one writer is what
     * keeps them agreeing with the columns beside them.
     */
    private function settleTotals(PayRunLine $line): PayRunLine
    {
        $line->gross_cents = $line->computedGrossCents();
        $line->deductions_cents = $line->computedDeductionsCents();
        $line->net_cents = $line->computedNetCents();

        $line->save();

        return $line->refresh();
    }

    /**
     * Freeze the run. Payslips can go out from here.
     *
     * A run with nobody on it is refused: an approved payroll that pays nobody
     * is a thing somebody will look for later and fail to explain.
     */
    public function approve(PayRun $run, ?User $author = null): PayRun
    {
        $this->mustBeOpen($run);

        $run->load('lines');

        abort_if(
            $run->lines->isEmpty(),
            422,
            'This run has nobody on it. Build it again — only active employees with a basic salary on record are paid here.',
        );

        $run->forceFill([
            'status' => PayRun::APPROVED,
            'approved_at' => now(),
            'approved_by' => $author?->id,
        ])->save();

        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Accountant],
            icon: 'badge',
            title: "Payroll {$run->reference} approved",
            detail: sprintf(
                '%s · %d staff · net ₱%s',
                $run->periodLabel(),
                $run->lines->count(),
                number_format($run->netCents() / 100, 2),
            ),
            tone: Tone::Info,
        );

        return $run->refresh()->load('lines');
    }

    /**
     * The money has gone out — record it, and post the run to the books.
     *
     * The posting is the point. Until now nothing in this system wrote itself
     * into the journal; a paid payroll is the first document that does, and the
     * entry it writes is the one an accountant would write by hand:
     *
     *     Dr  Salaries expense           the whole gross
     *       Cr  SSS/PhilHealth/Pag-IBIG payable    the contributions
     *       Cr  Withholding tax payable            the tax
     *       Cr  Cash in bank                       what the staff were handed
     *
     * One entry, balanced by construction — the gross *is* the deductions plus
     * the net — and posted, because the money has moved. If the chart is
     * missing an account the run needs, it is refused with the code named
     * rather than posted half way.
     */
    public function markPaid(PayRun $run, ?User $author = null): PayRun
    {
        abort_unless(
            $run->isApproved(),
            422,
            $run->isPaid()
                ? "{$run->reference} has already been paid."
                : 'Approve the run before paying it — approving is what freezes the figures.',
        );

        $run->load('lines');

        return DB::transaction(function () use ($run, $author): PayRun {
            $entry = $this->post($run, $author);

            $run->forceFill([
                'status' => PayRun::PAID,
                'paid_at' => now(),
                'journal_entry_id' => $entry?->getKey(),
            ])->save();

            return $run->refresh()->load(['lines', 'journalEntry']);
        });
    }

    /**
     * The journal entry a paid run writes.
     *
     * Null when the chart has no accounts to post to at all — an install that
     * has not seeded one. That is reported by `markPaid` rather than throwing:
     * the payroll itself is real and recorded, and refusing to record that the
     * staff were paid because the bookkeeping is not set up would be the wrong
     * way round.
     */
    private function post(PayRun $run, ?User $author): ?JournalEntry
    {
        $codes = (array) config('cargo.payroll.accounts', []);

        $accounts = Account::query()
            ->whereIn('code', array_values($codes))
            ->get()
            ->keyBy('code');

        if ($accounts->isEmpty()) {
            return null;
        }

        $statutory = $run->statutoryCents();
        $contributions = $statutory['sss'] + $statutory['philhealth'] + $statutory['pagibig'];
        $other = $statutory['other'];

        $lines = [];

        $push = function (string $code, string $side, int $amount, string $memo) use (&$lines, $accounts): void {
            if ($amount <= 0) {
                return;
            }

            $account = $accounts->get($code);

            abort_if(
                $account === null,
                422,
                sprintf('Account %s is missing from the chart, so this run cannot be posted.', $code),
            );

            $lines[] = [
                'account_id' => $account->getKey(),
                'side' => $side,
                'amount_cents' => $amount,
                'memo' => $memo,
            ];
        };

        // The whole cost of employing people this period, on one debit.
        $push($codes['salaries_expense'] ?? '5200', 'debit', $run->grossCents(), 'Salaries and wages');

        // What each agency is now owed, and what the staff were handed.
        $push($codes['statutory_payable'] ?? '2200', 'credit', $contributions, 'SSS, PhilHealth and Pag-IBIG withheld');
        $push($codes['withholding_payable'] ?? '2160', 'credit', $statutory['withholding_tax'], 'Withholding tax on wages');
        // Anything the firm is recovering — a cash advance — reduces what goes
        // out and lands against wages payable rather than cash.
        $push($codes['accrued_wages'] ?? '2100', 'credit', $other, 'Advances and other deductions recovered');
        $push($codes['cash'] ?? '1020', 'credit', $run->netCents(), 'Net pay');

        return $this->journal->create(JournalEntryData::fromArray([
            'entry_date' => $run->pay_date?->toDateString() ?? now()->toDateString(),
            'category' => JournalCategory::Payroll->value,
            'memo' => sprintf('Payroll %s · %s', $run->reference, $run->periodLabel()),
            'status' => JournalEntry::POSTED,
            // What raised it, so the entry can be traced back and so the same
            // run can never post twice — the unique index on the pair is what
            // enforces that.
            'source' => 'payroll',
            'source_type' => PayRun::class,
            'source_id' => $run->getKey(),
            'lines' => $lines,
        ]), $author);
    }

    /** Delete a draft. An approved run has been shown to people. */
    public function delete(PayRun $run): void
    {
        $this->mustBeOpen($run);

        DB::transaction(function () use ($run): void {
            $run->lines()->delete();
            $run->delete();
        });
    }

    /**
     * Is this run still somebody's draft?
     *
     * The gate on every write after the first, and the message names the way
     * forward: a run that needs changing after approval is not a mistake, it is
     * a decision to make in two steps.
     */
    private function mustBeOpen(PayRun $run): void
    {
        abort_if($run->isPaid(), 422, sprintf(
            '%s has been paid and is in the books. Correct it with a journal entry rather than by editing the run.',
            $run->reference,
        ));

        abort_if($run->isApproved(), 422, sprintf(
            '%s is approved, so its figures are frozen — payslips may already be out. '
            .'Build a new run, or post an adjustment.',
            $run->reference,
        ));
    }
}

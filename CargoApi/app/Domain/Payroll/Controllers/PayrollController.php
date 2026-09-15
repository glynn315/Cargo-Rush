<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Resources\PayRunResource;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Payroll — the run, and the four things you can do to one.
 *
 * Build, adjust, approve, pay. Every one of them is a verb rather than a status
 * PATCH, for the reason confirming a trip and posting a journal entry are:
 * approving freezes figures and tells the office, and paying writes the journal
 * entry that puts the run in the books. A status field that did either when set
 * to a particular value would hide what actually happened.
 *
 * Rebuilding is a POST to the run rather than a PUT, because it is not an edit:
 * it throws the lines away and works them out again from the employee records
 * as they now stand.
 */
class PayrollController extends ApiController
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $runs = $this->payroll->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(PayRunResource::collection($runs), $runs);
    }

    public function show(PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource($this->payroll->find($run->getKey())));
    }

    /**
     * The legal pay periods in a month — what the choice on screen is made of.
     *
     * Payroll is cut off on the 1st and the 16th, so a period is one of two
     * per month rather than a range somebody types. Sent by the API for the
     * reason the account types and the journal categories are: it is a fixed
     * set the server owns, and a client working the calendar out for itself
     * would be a second place to get February wrong — or to keep offering two
     * halves to an office that has switched to paying monthly.
     *
     * With no `month`, this answers with the month holding the period that has
     * just closed, and flags that period as the suggested one. That is what an
     * office wants on a cutoff day: on the 3rd of October, the back half of
     * September.
     */
    public function periods(Request $request): JsonResponse
    {
        $suggested = PayPeriod::justClosed();
        $month = $this->month($request) ?? $suggested->start;

        $periods = array_map(
            static fn (PayPeriod $period): array => [
                ...$period->toArray(),
                'suggested' => $period->matches($suggested),
            ],
            PayPeriod::inMonth((int) $month->year, (int) $month->month),
        );

        return $this->payload($periods, ['month' => $month->format('Y-m')]);
    }

    /**
     * Open a run for a period and work everybody's pay out.
     *
     * The period is **not** the office's to invent: it is the 1st to the 15th
     * or the 16th to the end of the month, because the statutory figures on
     * every payslip are half a month's contributions and a semi-monthly tax
     * table. A run covering the 3rd to the 20th would be wrong twice and wrong
     * invisibly — see `PayPeriod`.
     *
     * `pay_date` is when the money actually goes out, which is a different day
     * and the one the journal entry is dated. It defaults to the cutoff on
     * screen and is otherwise left alone: paying on the 20th for a period that
     * closed on the 16th is ordinary, and the books care when the money moved.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            // Usually the cutoff or a few days after it. Not restricted to the
            // future: an office catching up on last month's payroll is
            // ordinary, and refusing it would send them to the database.
            'pay_date' => ['required', 'date'],
        ]);

        $start = Carbon::parse($validated['period_start']);
        $period = PayPeriod::matching($start, Carbon::parse($validated['period_end']));

        /**
         * Refused here, at the boundary, and deliberately not inside
         * `PayrollService::build()`.
         *
         * Rebuilding passes a run's own stored dates back through `build()`, so
         * a guard there would make every run opened before this rule existed
         * un-rebuildable — punishing the office for a rule they could not have
         * followed. New runs must be a half-month; old ones stay workable.
         */
        if ($period === null) {
            throw ValidationException::withMessages([
                'period_start' => [PayPeriod::explainFor($start)],
            ]);
        }

        $run = $this->payroll->build(
            $period->start,
            $period->end,
            Carbon::parse($validated['pay_date']),
            $this->user($request),
        );

        return $this->item(new PayRunResource($run), status: 201);
    }

    /**
     * Work it out again, from the employee records as they now stand.
     *
     * The lines are replaced rather than merged — a run whose lines came from
     * two different calculations is a run nobody can check.
     */
    public function rebuild(Request $request, PayRun $run): JsonResponse
    {
        $rebuilt = $this->payroll->build(
            $run->period_start,
            $run->period_end,
            $run->pay_date,
            $this->user($request),
            $run,
        );

        return $this->item(new PayRunResource($rebuilt));
    }

    /**
     * Correct one payslip — an allowance, overtime, a cash advance, or a
     * statutory figure the table got wrong.
     *
     * The tax is deliberately not recomputed from the new gross; see
     * `PayrollService::adjustLine()`.
     */
    public function adjust(Request $request, PayRun $run, PayRunLine $line): JsonResponse
    {
        abort_unless($line->pay_run_id === $run->getKey(), 404, 'That payslip is not on this run.');

        $validated = $request->validate([
            'allowance_cents' => ['sometimes', 'integer', 'min:0'],
            'overtime_cents' => ['sometimes', 'integer', 'min:0'],
            // Signed: a bonus and a docked half-day are both real.
            'adjustments_cents' => ['sometimes', 'integer'],
            'adjustment_note' => ['nullable', 'string', 'max:255'],
            'sss_cents' => ['sometimes', 'integer', 'min:0'],
            'philhealth_cents' => ['sometimes', 'integer', 'min:0'],
            'pagibig_cents' => ['sometimes', 'integer', 'min:0'],
            'withholding_tax_cents' => ['sometimes', 'integer', 'min:0'],
            'other_deductions_cents' => ['sometimes', 'integer', 'min:0'],
            'deduction_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->payroll->adjustLine($line, $validated);

        return $this->item(new PayRunResource($this->payroll->find($run->getKey())));
    }

    /** Freeze it. Payslips can go out from here. */
    public function approve(Request $request, PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource(
            $this->payroll->approve($run, $this->user($request)),
        ));
    }

    /** The money has gone out — and this is what posts it to the books. */
    public function pay(Request $request, PayRun $run): JsonResponse
    {
        return $this->item(new PayRunResource(
            $this->payroll->markPaid($run, $this->user($request)),
        ));
    }

    /** Delete a draft. An approved run has been shown to people. */
    public function destroy(PayRun $run): JsonResponse
    {
        $this->payroll->delete($run);

        return $this->noContent();
    }

    /** Who approved or paid it. Stamped from the token, never a payload. */
    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * `month=2026-09` off the query string, or null for "whichever is due".
     *
     * Unparseable is treated as absent rather than as a 422: this read only
     * offers a choice, and the honest answer to a month nobody can read is the
     * one the office is most likely to want.
     */
    private function month(Request $request): ?Carbon
    {
        $value = trim((string) $request->query('month', ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Resources;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One pay run, with everybody on it.
 *
 * The lines always come along. A run without them is a period and a total,
 * which is not something anybody can check — and the screen that shows a run is
 * the screen somebody checks it on before approving.
 *
 * The statutory figures are broken out per agency as well as totalled, because
 * the monthly remittance is filed per agency on its own form. A single
 * `deductions_cents` would turn that into a spreadsheet exercise.
 *
 * @mixin PayRun
 */
class PayRunResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $lines = $this->lines ?? collect();
        $schedule = app(PayrollService::class)->schedule();

        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            /** `1–15 Sep 2026` — how a period reads on a list. */
            'period_label' => $this->periodLabel(),
            'pay_date' => $this->pay_date?->toDateString(),

            'status' => $this->status,
            'approved_at' => $this->iso($this->approved_at),
            'approved_by_name' => $this->approvedBy?->name,
            'paid_at' => $this->iso($this->paid_at),

            /**
             * The entry this run posted, once it was paid.
             *
             * Null before that, and it is the link that says payroll reached
             * the books rather than stopping at a spreadsheet.
             */
            'journal_entry_id' => $this->journal_entry_id,
            'journal_reference' => $this->journalEntry?->reference,

            /**
             * Which cutoff this run is, and what the firm's deduction policy
             * says about it.
             *
             * On screen this is what makes a ₱0.00 SSS line legible. A payslip
             * with no contributions on it is either correct — because the firm
             * takes them all on the other cutoff — or a mistake, and the reader
             * cannot tell which without being told the policy. Sent with the
             * run rather than fetched from the company separately, so the
             * figures and the explanation of them arrive together.
             */
            'is_first_cutoff' => $this->isFirstCutoff(),
            'deduct_on' => $schedule->value,
            'deduct_on_label' => $schedule->label(),
            'deduct_on_detail' => $schedule->detail(),
            'carries_contributions' => $schedule->carriedOn(
                $this->isFirstCutoff(),
                $this->isOnlyRunOfMonth(),
            ),

            'staff_count' => $lines->count(),
            'gross_cents' => $this->grossCents(),
            'deductions_cents' => $this->deductionsCents(),
            'net_cents' => $this->netCents(),
            'statutory' => $this->statutoryCents(),
            'currency' => 'PHP',

            /**
             * What the client may offer.
             *
             * From the API, because the rules are the API's: a draft can be
             * rebuilt, edited, approved and deleted; an approved run can only
             * be paid; a paid one is finished. Two clients working that out
             * from `status` is two places to get it wrong.
             */
            'can_edit' => $this->isDraft(),
            'can_approve' => $this->isDraft(),
            'can_pay' => $this->isApproved(),

            'notes' => $this->notes,

            'lines' => $lines->map(static fn (PayRunLine $line): array => [
                'id' => $line->id,
                'employee_id' => $line->employee_id,
                'employee_no' => $line->employee_no,
                'name' => $line->name,
                'position' => $line->position,

                'basic_cents' => $line->basic_cents,
                'allowance_cents' => $line->allowance_cents,
                'overtime_cents' => $line->overtime_cents,
                'adjustments_cents' => $line->adjustments_cents,
                'adjustment_note' => $line->adjustment_note,

                'sss_cents' => $line->sss_cents,
                'philhealth_cents' => $line->philhealth_cents,
                'pagibig_cents' => $line->pagibig_cents,
                'withholding_tax_cents' => $line->withholding_tax_cents,
                'other_deductions_cents' => $line->other_deductions_cents,
                'deduction_note' => $line->deduction_note,

                'gross_cents' => $line->gross_cents,
                'deductions_cents' => $line->deductions_cents,
                'net_cents' => $line->net_cents,
            ])->values()->all(),

            ...$this->stamps(),
        ];
    }
}

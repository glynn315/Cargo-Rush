<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's pay on one run — the payslip, as a row.
 *
 * Every figure on it is **copied**, including the person's name and position.
 * That is not duplication for its own sake: a payslip is a statement about a
 * fortnight, and it has to keep saying what it said after the employee gets a
 * rise, changes department or leaves. A line that read those back through the
 * employee record would rewrite every payslip ever issued the moment HR edited
 * anything — the same class of bug as an invoice that re-quotes its own tax.
 *
 * `gross_cents`, `deductions_cents` and `net_cents` are stored too, which this
 * codebase otherwise refuses to do. Same argument, and it is the exception
 * worth making: those three are what somebody was handed on paper.
 * `PayrollService` is the only thing that writes them.
 */
class PayRunLine extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'pay_run_id', 'employee_id', 'employee_no', 'name', 'position',
        'basic_cents', 'allowance_cents', 'overtime_cents',
        'adjustments_cents', 'adjustment_note',
        'sss_cents', 'philhealth_cents', 'pagibig_cents',
        'withholding_tax_cents', 'other_deductions_cents', 'deduction_note',
        'gross_cents', 'deductions_cents', 'net_cents',
    ];

    protected function casts(): array
    {
        return [
            'basic_cents' => 'integer',
            'allowance_cents' => 'integer',
            'overtime_cents' => 'integer',
            'adjustments_cents' => 'integer',
            'sss_cents' => 'integer',
            'philhealth_cents' => 'integer',
            'pagibig_cents' => 'integer',
            'withholding_tax_cents' => 'integer',
            'other_deductions_cents' => 'integer',
            'gross_cents' => 'integer',
            'deductions_cents' => 'integer',
            'net_cents' => 'integer',
        ];
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * The person this pays.
     *
     * Kept for the link — a payslip should open the employee, and next period's
     * run reads their salary from here — but nothing on this row is read
     * through it. See the note at the top.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Earnings, before anything comes off.
     *
     * Adjustments are inside the gross because that is where a bonus belongs:
     * it is pay. A deduction dressed up as a negative adjustment lands here
     * too, which is why the note beside it is worth filling in.
     */
    public function computedGrossCents(): int
    {
        return $this->basic_cents
            + $this->allowance_cents
            + $this->overtime_cents
            + $this->adjustments_cents;
    }

    /** Everything coming off, whoever it goes to. */
    public function computedDeductionsCents(): int
    {
        return $this->sss_cents
            + $this->philhealth_cents
            + $this->pagibig_cents
            + $this->withholding_tax_cents
            + $this->other_deductions_cents;
    }

    /** What the person is actually handed. */
    public function computedNetCents(): int
    {
        return $this->computedGrossCents() - $this->computedDeductionsCents();
    }

    /**
     * Do the stored totals still match their parts?
     *
     * They always should — `PayrollService` writes all three together — and a
     * mismatch means something wrote to this table directly. Worth being able
     * to ask, because the three stored figures are the ones on the paper.
     */
    public function isConsistent(): bool
    {
        return $this->gross_cents === $this->computedGrossCents()
            && $this->deductions_cents === $this->computedDeductionsCents()
            && $this->net_cents === $this->computedNetCents();
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll: a run per period, and a line per person on it.
 *
 * HR has the people — `employees`, with a base salary on each — and the daily
 * ledger has driver and helper salary columns for what a run cost. Neither is
 * payroll. What was missing is the thing in between: a **pay run**, which takes
 * a period, works out what each person is owed, deducts what the government is
 * owed, and produces a figure to pay and a payslip to hand over.
 *
 * ## Two tables, and why the lines are frozen
 *
 * `pay_runs` is the period and its state: drafted, approved, paid. `pay_run_lines`
 * is one employee's pay on it — and every figure is **copied onto the line**
 * rather than read back through the employee record.
 *
 * That is the important decision. A payslip is a statement about a moment: it
 * says what somebody's basic pay was in the first half of September, and it has
 * to keep saying that after they get a rise in October. A line that computed
 * itself from `employees.base_salary_cents` would quietly restate every payslip
 * ever issued the first time somebody's salary changed — the same class of bug
 * as an invoice that re-quotes its own tax.
 *
 * ## The deductions
 *
 * SSS, PhilHealth, Pag-IBIG and withholding tax, each its own column because
 * each is remitted to a different agency on a different form, and a single
 * `deductions_cents` would make the monthly remittance a spreadsheet exercise.
 * The rates come from configuration (`cargo.payroll`), and the amounts are
 * frozen on the line for the reason above.
 *
 * ## What it does not do
 *
 * No timekeeping. Hours, overtime and night differential are a module of their
 * own, and a fleet that has not asked for one is better served by a pay run it
 * can adjust by hand — `adjustments_cents`, with a note — than by a clock it
 * has to fight. Leave and undertime already exist in HR and are deducted the
 * same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /** `PR-2026-0001`, in its own series per company. */
            $table->string('reference');

            /**
             * The period the pay covers — not the day it is paid.
             *
             * A Philippine fleet pays twice a month (the 15th and the end), so
             * these are usually the 1st–15th and the 16th–end. Both ends are
             * stored because a run is filed, reported and audited by the period
             * it covers.
             */
            $table->date('period_start');
            $table->date('period_end');
            /** When the money actually goes out, which is a different day. */
            $table->date('pay_date');

            /**
             * draft → approved → paid.
             *
             * A draft is somebody's work in progress and can be recalculated
             * from scratch. Approving it freezes the figures — that is the
             * point of the step, because a payslip somebody has been handed
             * must not change under them. `paid` records that the money went
             * out, and is what the journal posting hangs off.
             */
            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            /**
             * The journal entry this run posted, once it was paid.
             *
             * Null until then. It is what makes payroll part of the books
             * rather than a spreadsheet beside them: salaries to expense, the
             * statutory deductions to their payables, the net to cash.
             */
            $table->foreignUlid('journal_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'period_start']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('pay_run_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            // Cascading: a line without its run is not a payslip.
            $table->foreignUlid('pay_run_id')->constrained()->cascadeOnDelete();
            /**
             * Restricted, unlike the run. A payslip names a person, and an
             * employee record cannot be deleted out from under one that has
             * been issued — HR retires them instead.
             */
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();

            /**
             * The person as they were on this run.
             *
             * Copied, like every figure below. A payslip that read the name and
             * the position back through the employee record would rewrite
             * somebody's history the day they were promoted.
             */
            $table->string('employee_no');
            $table->string('name');
            $table->string('position')->nullable();

            // ---- Earnings.
            $table->bigInteger('basic_cents')->default(0);
            /** Allowances, per diem, anything paid on top and not taxed as pay. */
            $table->bigInteger('allowance_cents')->default(0);
            /** Overtime, if the office works it out by hand. */
            $table->bigInteger('overtime_cents')->default(0);
            /**
             * Anything else, plus or minus, with a reason.
             *
             * The escape hatch that keeps this usable without a timekeeping
             * module: a bonus, a correction, a deduction for a broken tail
             * light. Signed, because both directions are real.
             */
            $table->bigInteger('adjustments_cents')->default(0);
            $table->string('adjustment_note')->nullable();

            // ---- Statutory deductions, one column each because each is
            // remitted separately on its own form.
            $table->bigInteger('sss_cents')->default(0);
            $table->bigInteger('philhealth_cents')->default(0);
            $table->bigInteger('pagibig_cents')->default(0);
            $table->bigInteger('withholding_tax_cents')->default(0);
            /** Cash advances, loans, anything the firm is recovering. */
            $table->bigInteger('other_deductions_cents')->default(0);
            $table->string('deduction_note')->nullable();

            /**
             * Gross and net, stored rather than derived.
             *
             * The one place this codebase stores a total it could compute — and
             * for the same reason the rates are frozen: a payslip is a document,
             * and it has to say next year what it said when it was handed over.
             * `PayrollService` is the only thing that writes them.
             */
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('deductions_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);

            $table->timestamps();

            // One line per person per run. Two would mean somebody paid twice.
            $table->unique(['pay_run_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_lines');
        Schema::dropIfExists('pay_runs');
    }
};

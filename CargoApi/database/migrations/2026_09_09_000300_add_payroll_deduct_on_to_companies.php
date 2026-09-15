<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\DeductionSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which cutoff a firm takes the monthly contributions on.
 *
 * A column on the company rather than a line in `config/cargo.php`, and the
 * distinction is worth stating: the SSS *rate* is the government's and is the
 * same for every firm on the platform, so it belongs in configuration. Which
 * payslip a firm chooses to load the contributions onto is the firm's own
 * policy, differs between two companies in the same yard, and has to be
 * editable without a deployment. That is what a column is for — the same reason
 * `vat_rate_bp` is one.
 *
 * Defaults to `split`, which is what payroll did before this column existed, so
 * no firm's next run changes shape because of the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('payroll_deduct_on', 10)
                ->default(DeductionSchedule::Split->value)
                ->after('vat_rate_bp');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('payroll_deduct_on');
        });
    }
};

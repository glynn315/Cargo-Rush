<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One payroll period, and everybody paid on it.
 *
 * ## Three states, and two of them are one-way
 *
 * A **draft** can be recalculated from scratch — that is what it is for. The
 * office builds it, looks at it, adds an adjustment, builds it again.
 *
 * **Approved** freezes it. Somebody has looked at the figures and said yes, and
 * from that moment a payslip can be handed over — which means the figures must
 * stop moving. This is the same argument a posted journal entry makes, for the
 * same reason: a document that can be quietly restated after it has been given
 * to somebody is not a record of anything.
 *
 * **Paid** records that the money went out, and is what posts the run to the
 * books: salaries to expense, the statutory deductions to their payables, the
 * net to cash. Nothing before that touches the ledger, because nothing before
 * that has happened.
 */
class PayRun extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /** Work in progress. Recalculable, editable, deletable. */
    public const DRAFT = 'draft';

    /** Signed off. The figures are frozen and payslips can go out. */
    public const APPROVED = 'approved';

    /** The money has gone out, and the books have it. */
    public const PAID = 'paid';

    protected $fillable = [
        'reference', 'period_start', 'period_end', 'pay_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayRunLine::class)->orderBy('name');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The entry this run posted, once it was paid. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /** Past changing: approved or paid. */
    public function isLocked(): bool
    {
        return ! $this->isDraft();
    }

    public function grossCents(): int
    {
        return (int) $this->lines->sum('gross_cents');
    }

    public function deductionsCents(): int
    {
        return (int) $this->lines->sum('deductions_cents');
    }

    public function netCents(): int
    {
        return (int) $this->lines->sum('net_cents');
    }

    /**
     * What each agency is owed out of this run.
     *
     * Kept apart rather than as one figure, because each is remitted on its own
     * form to its own agency — and the month's remittance is the sum of these
     * across runs, which a single total could not answer.
     *
     * @return array<string, int>
     */
    public function statutoryCents(): array
    {
        return [
            'sss' => (int) $this->lines->sum('sss_cents'),
            'philhealth' => (int) $this->lines->sum('philhealth_cents'),
            'pagibig' => (int) $this->lines->sum('pagibig_cents'),
            'withholding_tax' => (int) $this->lines->sum('withholding_tax_cents'),
            'other' => (int) $this->lines->sum('other_deductions_cents'),
        ];
    }

    /**
     * Is this the 1st-to-15th payslip?
     *
     * Which cutoff a run is decides how much of a monthly salary and how much
     * of a monthly contribution it carries — see `PayPeriod::classify()`, which
     * is the one place that answers it.
     */
    public function isFirstCutoff(): bool
    {
        return $this->cutoff()['first'];
    }

    /** True where payroll runs once a month, so this run is the whole of it. */
    public function isOnlyRunOfMonth(): bool
    {
        return $this->cutoff()['only'];
    }

    /** @return array{first: bool, only: bool} */
    private function cutoff(): array
    {
        if ($this->period_start === null || $this->period_end === null) {
            return ['first' => true, 'only' => false];
        }

        return PayPeriod::classify($this->period_start, $this->period_end);
    }

    /** How the period reads on a list: `1–15 Sep 2026`. */
    public function periodLabel(): string
    {
        if ($this->period_start === null || $this->period_end === null) {
            return '';
        }

        return $this->period_start->format('j').'–'.$this->period_end->format('j M Y');
    }

    public function scopeInPayrollOrder(Builder $query): Builder
    {
        return $query->orderByDesc('period_start')->orderByDesc('reference');
    }

    /** Same rule as a trip or an invoice: the reference is the system's. */
    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->reference ??= static::nextReference();
        });
    }

    /**
     * The next reference in the PR-YYYY-#### series.
     *
     * Numbered within the year, like an invoice, because payroll is filed and
     * reported by the year it belongs to. `withTrashed` so a deleted draft does
     * not hand its number to the next run — a reference on a payslip has been
     * quoted to somebody.
     */
    public static function nextReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = "PR-{$year}-";

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last === null ? 0 : (int) substr((string) $last, strlen($prefix));

        return $prefix.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of the chart of accounts.
 *
 * A name, a number and a type. The type is the part that does the work — it
 * decides which side increases this account, which statement it appears on and
 * which column of a trial balance it lands in (see `AccountType`).
 *
 * No balance column, and that is deliberate. A balance is `journal_lines`
 * summed over a period, and the moment it is also a column on this row there
 * are two answers to the same question — one of them stale the first time a
 * posting is voided. `GeneralLedgerService` computes it.
 */
class Account extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /**
     * `is_system` is here for the seeder that plants the starting chart, and
     * for nothing else: no request or DTO in this domain carries it, so an
     * office cannot promote its own account into one that cannot be deleted.
     */
    protected $fillable = [
        'code', 'name', 'type', 'group', 'description', 'position', 'status', 'is_system',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'status' => StatusValue::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * Every posting against this account, whatever its entry's status.
     *
     * Unfiltered on purpose: what counts towards a balance is a decision about
     * the *entry* (draft and void do not), and making that decision here would
     * hide it from the one place that should be making it. The ledger joins
     * through to `journal_entries` and says so explicitly.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** The side that increases this account. Asked of the type, once. */
    public function normalBalance(): BalanceSide
    {
        return $this->type->normalBalance();
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    /**
     * Has anything ever been posted here?
     *
     * The question that decides whether an account can be deleted or only
     * retired. A `draft` counts: somebody is mid-way through writing it, and
     * deleting the account under them would leave a form that cannot be saved.
     */
    public function hasPostings(): bool
    {
        return $this->lines()->exists();
    }

    /** What a picker shows: `1010 · Cash on hand`. */
    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }

    /** The retired ones drop out — what a form should offer. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    /**
     * The order a chart of accounts is read in: by type, then by number.
     *
     * Not `orderBy('type')`, which would sort the five words alphabetically —
     * asset, equity, expense, income, liability — and put equity above
     * expenses, which is not a chart anybody recognises. The accounting order
     * belongs to `AccountType::position()`, and the CASE is built from it so
     * the two cannot drift. Every value in it comes from the enum, so there is
     * nothing here a caller could reach.
     *
     * Then `position`, so an office can lift one account to the top of its
     * group without renumbering the chart, and the code as the tie-break.
     */
    public function scopeInChartOrder(Builder $query): Builder
    {
        $order = collect(AccountType::cases())
            ->map(static fn (AccountType $type): string => sprintf(
                "WHEN '%s' THEN %d",
                $type->value,
                $type->position(),
            ))
            ->implode(' ');

        return $query
            ->orderByRaw("CASE accounts.type {$order} ELSE 99 END")
            ->orderBy('position')
            ->orderBy('code');
    }
}

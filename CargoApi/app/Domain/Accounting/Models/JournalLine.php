<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one transaction: an account, and an amount on one side of it.
 *
 * Never both sides. A line carries a debit or a credit and the other column is
 * zero, because "a debit of 500 and a credit of 200" is not one line an
 * accountant would ever write — it is a line of 300 in whichever direction, and
 * writing it the long way makes every total in the system ambiguous.
 * `JournalLineData` enforces that on the way in.
 *
 * No soft deletes, unlike almost everything else here. A line has no life of
 * its own: it exists as part of an entry, it is deleted only when a *draft*
 * entry is deleted (cascading), and a posted entry cannot be deleted at all.
 * Keeping a trashed line would mean a balance that depends on remembering to
 * exclude it.
 */
class JournalLine extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'journal_entry_id', 'account_id', 'line_no',
        'debit_cents', 'credit_cents', 'memo',
        'truck_id', 'trip_id', 'customer_id',
    ];

    protected function casts(): array
    {
        return [
            'debit_cents' => 'integer',
            'credit_cents' => 'integer',
            'line_no' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** What this side was about, where there is something to point at. */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Which side this line is on. A line is always on exactly one. */
    public function side(): BalanceSide
    {
        return $this->debit_cents !== 0 ? BalanceSide::Debit : BalanceSide::Credit;
    }

    /** The amount, whichever side it is on. */
    public function amountCents(): int
    {
        return $this->debit_cents !== 0 ? $this->debit_cents : $this->credit_cents;
    }

    /**
     * What this line does to its account's balance, in that account's own
     * direction.
     *
     * A debit on an asset raises it; a debit on a liability lowers it. The rule
     * lives in `AccountType::normalBalance()` and `BalanceSide::sign()`, and
     * this is the one place that applies it to a row — so a report that wants a
     * balance adds these up and does not have to know the rule at all.
     */
    public function effectOnAccount(): int
    {
        $normal = $this->account->normalBalance();

        return $normal->sign(BalanceSide::Debit) * $this->debit_cents
            + $normal->sign(BalanceSide::Credit) * $this->credit_cents;
    }
}

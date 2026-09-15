<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\BalanceSide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The general ledger — the journal read the other way round.
 *
 * A journal is chronological: what happened, in order. A ledger is by account:
 * everything that ever touched Cash, with a running balance down the page. They
 * are the same postings, and that is the point — there is no second set of
 * rows here, no balance column kept up to date by a trigger, and nothing that
 * can disagree with the journal. Every figure on every report in this class is
 * a sum over `journal_lines`, computed when it is asked for.
 *
 * That is a deliberate trade. A stored balance would be faster to read; it
 * would also be a second version of the truth, wrong from the first void
 * nobody remembered to decrement, and impossible to reconcile without
 * recomputing anyway — at which point the stored figure has bought nothing and
 * cost trust.
 *
 * ## What counts
 *
 * Only **posted** entries. A draft is somebody's work in progress and a void
 * has been withdrawn; neither has happened as far as the books are concerned.
 * The filter is applied once, in `postings()`, and every figure below goes
 * through it.
 *
 * ## Opening balances, and the two kinds of account
 *
 * A ledger for a range needs to know where the account stood before it. For an
 * asset, a liability or equity that is everything ever posted before the range
 * began — a truck owned in December is owned in January. For income and expense
 * it is nothing: those accounts tell the story of *a* period and start again,
 * which is what closing the books means. `AccountType::isPermanent()` is where
 * that distinction lives, and this class asks it rather than keeping a list.
 *
 * ## Signs
 *
 * Every balance is returned in the account's own normal direction — positive
 * means "more of what this account is". Cash of ₱50,000 is 50000_00, and so is
 * a payable of ₱50,000: the liability is not negative money, it is fifty
 * thousand pesos of debt. The debit and credit totals are reported alongside,
 * so a client that wants the raw sides has them without doing arithmetic on a
 * signed figure.
 */
class GeneralLedgerService
{
    /**
     * One account's ledger for a period: where it stood, what moved, and the
     * running balance after each posting.
     *
     * @return array<string, mixed>
     */
    public function forAccount(Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $opening = $this->openingBalance($account, $from);

        $lines = $this->postings()
            ->select('journal_lines.*')
            ->where('journal_lines.account_id', $account->getKey())
            ->when($from !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '<=', $to))
            // The order a ledger page is read in, and the order the running
            // balance depends on: by the day the transaction belongs to, then
            // by the reference within the day, then by the line's own place in
            // its entry. Without the last two, two postings on one date would
            // come back in whatever order the database chose and the running
            // balance would be different on every refresh.
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.reference')
            ->orderBy('journal_lines.line_no')
            ->with(['entry:id,reference,entry_date,category,memo,status'])
            ->get();

        $normal = $account->normalBalance();
        $running = $opening;
        $debits = 0;
        $credits = 0;

        $rows = $lines->map(function (JournalLine $line) use ($normal, &$running, &$debits, &$credits): array {
            $debits += $line->debit_cents;
            $credits += $line->credit_cents;

            // The account's own direction, so the column reads as a balance
            // rather than as a signed difference between two sides.
            $running += $normal->sign(BalanceSide::Debit) * $line->debit_cents
                + $normal->sign(BalanceSide::Credit) * $line->credit_cents;

            return [
                'line_id' => $line->getKey(),
                'entry_id' => $line->journal_entry_id,
                'reference' => $line->entry?->reference,
                'entry_date' => $line->entry?->entry_date?->toDateString(),
                'category' => $line->entry?->category?->value,
                'memo' => $line->memo ?? $line->entry?->memo,
                'debit_cents' => $line->debit_cents,
                'credit_cents' => $line->credit_cents,
                'balance_cents' => $running,
                'truck_id' => $line->truck_id,
                'trip_id' => $line->trip_id,
                'customer_id' => $line->customer_id,
            ];
        })->all();

        return [
            'account' => [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'group' => $account->group,
                'normal_balance' => $normal->value,
            ],
            'range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            /**
             * Where the account stood before the range.
             *
             * Always zero for income and expense — see the note at the top on
             * why those two do not carry over — and the clients need not know
             * the rule, because the figure is already right.
             */
            'opening_balance_cents' => $opening,
            'debit_cents' => $debits,
            'credit_cents' => $credits,
            'movement_cents' => $running - $opening,
            'closing_balance_cents' => $running,
            'entry_count' => $lines->pluck('journal_entry_id')->unique()->count(),
            'lines' => $rows,
            'currency' => 'PHP',
        ];
    }

    /**
     * A trial balance: every account with a balance, and the two columns that
     * have to agree.
     *
     * The check that the books are whole. Debits and credits are equal in every
     * entry, so they are equal in total, so the two columns of a trial balance
     * agree — and if they ever do not, something has written to `journal_lines`
     * without going through `JournalService`. `balanced` says so plainly rather
     * than leaving somebody to add up two columns of figures by eye.
     *
     * Accounts with no balance are left out — including one that moved and came
     * back to nothing, like a receivable raised and then collected. A trial
     * balance is a list of balances: a row of two zeroes contributes to neither
     * column, and forty of them hide the eight lines that matter. The movement
     * itself is not lost, it is on that account's own ledger page.
     *
     * @return array<string, mixed>
     */
    public function trialBalance(?Carbon $asOf = null): array
    {
        $totals = $this->balances(null, $asOf);

        $accounts = Account::query()->inChartOrder()->get();
        $rows = [];
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($accounts as $account) {
            $balance = $totals[$account->getKey()] ?? null;

            if ($balance === null) {
                continue;
            }

            $normal = $account->normalBalance();
            $signed = $normal->sign(BalanceSide::Debit) * $balance['debit']
                + $normal->sign(BalanceSide::Credit) * $balance['credit'];

            // Nothing to report on either side. See the note above on why a
            // netted-off account is not a row here.
            if ($signed === 0) {
                continue;
            }

            // Which column a balance lands in is decided by the balance itself,
            // not by the account's type: an expense account in credit (a refund
            // larger than the spend) belongs on the credit side, and printing
            // it as a negative debit would be a figure nobody could add up.
            $onDebitSide = $normal === BalanceSide::Debit ? $signed >= 0 : $signed < 0;
            $magnitude = abs($signed);

            $debitTotal += $onDebitSide ? $magnitude : 0;
            $creditTotal += $onDebitSide ? 0 : $magnitude;

            $rows[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'group' => $account->group,
                'debit_cents' => $onDebitSide ? $magnitude : 0,
                'credit_cents' => $onDebitSide ? 0 : $magnitude,
                'balance_cents' => $signed,
            ];
        }

        return [
            'as_of' => ($asOf ?? Carbon::now())->toDateString(),
            'rows' => $rows,
            'debit_total_cents' => $debitTotal,
            'credit_total_cents' => $creditTotal,
            /**
             * The whole point of the report.
             *
             * True is the only acceptable answer, and the difference is
             * reported rather than assumed to be zero so that a broken install
             * says how broken rather than merely that it is.
             */
            'balanced' => $debitTotal === $creditTotal,
            'difference_cents' => $debitTotal - $creditTotal,
            'currency' => 'PHP',
        ];
    }

    /**
     * The chart with a balance against each account — the ledger's index page.
     *
     * What the office lands on before picking an account: every account, its
     * type, and what it stands at. Grouped by type in the order a chart is
     * always read (`AccountType::position()`), with a subtotal per type,
     * because "what are my expenses" is the question a list of forty accounts
     * cannot answer on its own.
     *
     * @return array<string, mixed>
     */
    public function summary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $totals = $this->balances($from, $to);
        $accounts = Account::query()->inChartOrder()->get();

        $groups = [];

        foreach (AccountType::cases() as $type) {
            $groups[$type->value] = [
                'type' => $type->value,
                'label' => $type->label(),
                'normal_balance' => $type->normalBalance()->value,
                'accounts' => [],
                'debit_cents' => 0,
                'credit_cents' => 0,
                'balance_cents' => 0,
            ];
        }

        foreach ($accounts as $account) {
            $balance = $totals[$account->getKey()] ?? ['debit' => 0, 'credit' => 0];
            $normal = $account->normalBalance();
            $signed = $normal->sign(BalanceSide::Debit) * $balance['debit']
                + $normal->sign(BalanceSide::Credit) * $balance['credit'];

            $key = $account->type->value;

            $groups[$key]['accounts'][] = [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'group' => $account->group,
                'status' => $account->status->value,
                'debit_cents' => $balance['debit'],
                'credit_cents' => $balance['credit'],
                'balance_cents' => $signed,
            ];

            $groups[$key]['debit_cents'] += $balance['debit'];
            $groups[$key]['credit_cents'] += $balance['credit'];
            $groups[$key]['balance_cents'] += $signed;
        }

        return [
            'range' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'types' => array_values($groups),
            'currency' => 'PHP',
        ];
    }

    /**
     * Debits and credits per account over a window, in one query.
     *
     * One `group by` rather than a query per account: a chart of forty accounts
     * would otherwise be forty round trips to draw one page.
     *
     * Public because the financial statements are built on exactly this — an
     * income statement is these totals for the income and expense accounts over
     * a period, and a balance sheet is the same for the other three as at a
     * date. Sharing the primitive is what stops a statement disagreeing with
     * the trial balance beside it.
     *
     * @return array<string, array{debit: int, credit: int}>
     */
    public function balances(?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->postings()
            ->when($from !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->whereDate('journal_entries.entry_date', '<=', $to))
            ->groupBy('journal_lines.account_id')
            // `select` first and `selectRaw` after: the first replaces the
            // column list, the rest append to it. A `journal_lines.*` left in
            // front of an aggregate is a group-by error on any server that
            // checks (MySQL's ONLY_FULL_GROUP_BY does), which is why
            // `postings()` selects nothing of its own.
            ->select('journal_lines.account_id as account_id')
            ->selectRaw('SUM(journal_lines.debit_cents) as debit_total')
            ->selectRaw('SUM(journal_lines.credit_cents) as credit_total')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->account_id => [
                    'debit' => (int) $row->debit_total,
                    'credit' => (int) $row->credit_total,
                ],
            ])
            ->all();
    }

    /**
     * Where an account stood before a range began.
     *
     * Zero for income and expense, and not because it is convenient: those
     * accounts are the story of one period. An expense ledger for March that
     * opened with February's fuel in it would answer a question nobody asked
     * and would double-count the moment somebody added the two months up.
     */
    private function openingBalance(Account $account, ?Carbon $from): int
    {
        if ($from === null || ! $account->type->isPermanent()) {
            return 0;
        }

        $sums = $this->postings()
            ->where('journal_lines.account_id', $account->getKey())
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_cents), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_cents), 0) as credit_total')
            ->first();

        $normal = $account->normalBalance();

        return $normal->sign(BalanceSide::Debit) * (int) ($sums->debit_total ?? 0)
            + $normal->sign(BalanceSide::Credit) * (int) ($sums->credit_total ?? 0);
    }

    /**
     * Every line that counts, and nothing else.
     *
     * The one place the "posted only" rule is written. A draft has not happened
     * and a void has been taken back, so neither belongs in a balance — and
     * because every figure in this class starts here, there is no report that
     * can forget it.
     *
     * The join is to `journal_entries` because the date and the status live on
     * the entry while the amounts live on the line. Only the line's own tenant
     * scope is applied, and that is enough rather than lax: a line and its
     * entry are stamped with the same company by the model layer in the same
     * transaction, so a line this company can see cannot be hanging off another
     * company's entry.
     *
     * No `select` of its own, deliberately. Each caller sets its own column
     * list — `journal_lines.*` to hydrate rows, the aggregates to total them —
     * and a `*` left in front of a `SUM` is a group-by error waiting for the
     * first server that checks.
     */
    private function postings(): Builder
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::POSTED)
            ->whereNull('journal_entries.deleted_at');
    }

    /**
     * The accounts a ledger page can be asked for, in chart order.
     *
     * @return Collection<int, Account>
     */
    public function accounts(): Collection
    {
        return Account::query()->inChartOrder()->get();
    }
}

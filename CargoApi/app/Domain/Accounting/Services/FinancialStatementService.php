<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\BalanceSide;
use Illuminate\Support\Carbon;

/**
 * The two statements the books exist to produce.
 *
 * The trial balance proves the ledger is whole; these say what it *means*. An
 * income statement is what the business earned over a period, and a balance
 * sheet is what it is worth at a moment — and the difference between "over" and
 * "at" is the whole reason they are two reports rather than one.
 *
 * Both are built from `GeneralLedgerService::balances()`, which is the same
 * primitive the trial balance uses. That is deliberate: a statement computed
 * its own way is a statement that can disagree with the ledger under it, and
 * the first time an accountant finds that, nothing on the screen is worth
 * anything.
 *
 * ## The period, and the two kinds of account
 *
 * Income and expense accounts are the story of a period and start again — so
 * the income statement takes a range and means exactly it. Assets, liabilities
 * and equity carry over, so the balance sheet takes a single date and sums
 * everything up to it. `AccountType::isPermanent()` is where that split lives.
 *
 * ## Where this period's profit sits
 *
 * There is no period close in this system yet — nothing sweeps the year's
 * income and expenses into retained earnings. So the balance sheet carries the
 * result to date as its own equity line, `Earnings not yet closed`, rather than
 * silently folding it into an account nobody posted to. Without it the sheet
 * would not balance, and a reader would be left hunting for the difference; with
 * it, the arithmetic is visible and the missing step is named.
 */
class FinancialStatementService
{
    public function __construct(private readonly GeneralLedgerService $ledger) {}

    /**
     * What the business earned over a period.
     *
     * Revenue, then the cost of providing the service, then everything else —
     * which is the order a haulier reads it in, because the gap between the
     * first two is the only figure that says whether the hauling itself pays.
     *
     * @return array<string, mixed>
     */
    public function incomeStatement(?Carbon $from = null, ?Carbon $to = null): array
    {
        $income = $this->sections(AccountType::Income, $from, $to);
        $expense = $this->sections(AccountType::Expense, $from, $to);

        $revenue = $this->sum($income);
        $expenses = $this->sum($expense);

        /**
         * The cost of actually hauling, as against running an office.
         *
         * Taken from the account group rather than guessed at from the account
         * numbers, and configurable because it is the office's own label — the
         * seeded chart calls it "Cost of services". An install that renames it
         * gets a null gross profit rather than a wrong one, which is the right
         * failure: a margin computed from the wrong half of the expenses is
         * worse than no margin at all.
         */
        $costLabel = (string) config('cargo.accounting.cost_of_services_group', 'Cost of services');
        $costSection = collect($expense)->firstWhere('group', $costLabel);
        $cost = $costSection === null ? null : (int) $costSection['total_cents'];

        return [
            'range' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],

            'revenue' => [
                'groups' => $income,
                'total_cents' => $revenue,
            ],
            'expenses' => [
                'groups' => $expense,
                'total_cents' => $expenses,
            ],

            /**
             * Revenue less the cost of providing it, and what that is as a
             * percentage. Null when the chart has no cost-of-services group to
             * measure against — see above.
             */
            'cost_of_services_cents' => $cost,
            'gross_profit_cents' => $cost === null ? null : $revenue - $cost,
            'gross_margin_pct' => $cost === null || $revenue === 0
                ? null
                : round((($revenue - $cost) / $revenue) * 100, 1),

            'net_income_cents' => $revenue - $expenses,
            'net_margin_pct' => $revenue === 0
                ? null
                : round((($revenue - $expenses) / $revenue) * 100, 1),

            'currency' => 'PHP',
        ];
    }

    /**
     * What the business is worth at a moment.
     *
     * Assets on one side; what is owed and what the owners have on the other.
     * The two sides agree, or the books do not — and `balanced` says which
     * rather than leaving somebody to add up two columns by eye.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $assets = $this->sections(AccountType::Asset, null, $asOf);
        $liabilities = $this->sections(AccountType::Liability, null, $asOf);
        $equity = $this->sections(AccountType::Equity, null, $asOf);

        $assetTotal = $this->sum($assets);
        $liabilityTotal = $this->sum($liabilities);
        $equityTotal = $this->sum($equity);

        // Everything earned and spent up to this date, which nothing has closed
        // into equity yet. See the note at the top of this class.
        $earnings = $this->incomeStatement(null, $asOf)['net_income_cents'];

        $equityWithEarnings = $equityTotal + $earnings;

        return [
            'as_of' => $asOf->toDateString(),

            'assets' => ['groups' => $assets, 'total_cents' => $assetTotal],
            'liabilities' => ['groups' => $liabilities, 'total_cents' => $liabilityTotal],
            'equity' => [
                'groups' => $equity,
                'posted_total_cents' => $equityTotal,
                /**
                 * The period's result, sitting in equity because that is where
                 * it belongs and nothing has moved it there.
                 *
                 * Named on the statement rather than hidden: an accountant sees
                 * immediately that the books have not been closed, which is a
                 * fact about this install and not an error.
                 */
                'earnings_not_closed_cents' => $earnings,
                'total_cents' => $equityWithEarnings,
            ],

            'liabilities_and_equity_cents' => $liabilityTotal + $equityWithEarnings,
            'balanced' => $assetTotal === $liabilityTotal + $equityWithEarnings,
            'difference_cents' => $assetTotal - ($liabilityTotal + $equityWithEarnings),

            'currency' => 'PHP',
        ];
    }

    /**
     * One type's accounts, grouped by their own sub-heading.
     *
     * The group is the office's label (`accounts.group`) and the order is the
     * chart's, so a statement reads down the page the way the chart of accounts
     * does. Accounts with no movement and no balance are left out — a statement
     * padded with zeroes hides the lines that matter — and a group that empties
     * out disappears with them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sections(AccountType $type, ?Carbon $from, ?Carbon $to): array
    {
        $totals = $this->ledger->balances($from, $to);

        $groups = [];

        foreach (Account::query()->where('type', $type->value)->inChartOrder()->get() as $account) {
            $balance = $totals[$account->getKey()] ?? ['debit' => 0, 'credit' => 0];
            $normal = $account->normalBalance();

            $signed = $normal->sign(BalanceSide::Debit) * $balance['debit']
                + $normal->sign(BalanceSide::Credit) * $balance['credit'];

            if ($signed === 0) {
                continue;
            }

            // Null groups collect under the type's own name rather than under
            // an empty heading, which is what an account nobody has filed looks
            // like on a printed page.
            $label = $account->group ?? $type->label();

            $groups[$label] ??= ['group' => $label, 'accounts' => [], 'total_cents' => 0];

            $groups[$label]['accounts'][] = [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'balance_cents' => $signed,
            ];

            $groups[$label]['total_cents'] += $signed;
        }

        return array_values($groups);
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function sum(array $groups): int
    {
        return (int) collect($groups)->sum('total_cents');
    }
}

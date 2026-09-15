<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTO\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Collection;

/**
 * The chart of accounts.
 *
 * A small class with two rules worth the words, both of the same shape: a
 * chart is not a list of labels, it is the thing every posting in the books
 * points at, so the edits that would rewrite history are refused rather than
 * applied.
 *
 * **The type is fixed once anything is posted.** Changing an account from
 * expense to income flips the sign of every figure ever recorded against it —
 * the account was collecting money spent and now claims to have been
 * collecting money earned. That is not an edit, it is a silent restatement of
 * every period the account appears in, and nothing on any report would show
 * that it had happened.
 *
 * **An account with postings is retired, never deleted.** The postings are the
 * history and the account is what they name. The database says the same thing
 * with `restrictOnDelete`; this says it earlier and with a sentence, so the
 * office is told what to do instead of being handed a constraint violation.
 *
 * The same reasoning `ExpenseService` applies to a category with spend against
 * it — deliberately, because it is the same problem: a reference that
 * something depends on cannot be removed just because it is no longer in use.
 */
class AccountService
{
    /**
     * The whole chart, in the order a chart is read.
     *
     * @return Collection<int, Account>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return Account::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->inChartOrder()
            ->get();
    }

    /**
     * Add an account to the chart.
     *
     * Refreshed on the way out, for the reason the shared repository refreshes:
     * the payload deliberately omits what the columns default to — `status`
     * defaults to active, `position` to zero — and a model returned straight
     * from `create()` has nulls where those defaults are. The client would read
     * an account with no status, which is not a state an account has.
     */
    public function create(AccountData $data): Account
    {
        return Account::create($data->persistable())->refresh();
    }

    /**
     * Edit an account, except for the one thing that cannot be edited.
     *
     * The type check is here rather than in the request because whether it is
     * allowed depends on the account's history, not on the payload: the same
     * change is perfectly fine on an account nobody has posted to, which is the
     * common case of somebody fixing a mistake five minutes after making it.
     */
    public function update(Account $account, AccountData $data): Account
    {
        if ($data->type !== null && $data->type !== $account->type && $account->hasPostings()) {
            abort(422, sprintf(
                '%s already has postings against it, so it has to stay %s. '
                .'Retire it and open a new account of the right kind — the old postings keep their meaning.',
                $account->label(),
                $account->type->label(),
            ));
        }

        $account->update($data->persistable());

        return $account->refresh();
    }

    /**
     * Delete an account, or retire it.
     *
     * Two outcomes, and the caller is told which happened, because they look
     * nothing alike on screen: a deleted account is gone from the chart and a
     * retired one is still there, greyed, keeping its history. The office can
     * usually tell why — the account they just added by mistake goes, the one
     * from 2024 stays — but it should not have to guess.
     *
     * A seeded account is never deleted even when nothing has been posted to
     * it: the rest of the system posts to those by code, and an install missing
     * its cash account would fail somewhere far away from whoever tidied up.
     *
     * @return bool true when the row was deleted, false when it was retired
     */
    public function delete(Account $account): bool
    {
        if ($account->is_system || $account->hasPostings()) {
            $account->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $account->delete();

        return true;
    }

    /**
     * The five types, for a form that has to offer them.
     *
     * Served from the enum so a client never hardcodes the list, and with the
     * normal balance alongside — a form that knows an expense grows on the
     * debit side can put the cursor in the right column.
     *
     * @return array<int, array<string, mixed>>
     */
    public function types(): array
    {
        return array_map(static fn (AccountType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'normal_balance' => $type->normalBalance()->value,
            'permanent' => $type->isPermanent(),
            'position' => $type->position(),
        ], AccountType::cases());
    }
}

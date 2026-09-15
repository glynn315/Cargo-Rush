<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Requests\AccountRequest;
use App\Domain\Accounting\Resources\AccountResource;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The chart of accounts — what every posting in the books points at.
 *
 * Seeded with a chart a haulier can work from on day one (see
 * `ChartOfAccountsSeeder`), and extendable, because every fleet's accountant
 * keeps an account of their own eventually.
 *
 * Not paginated. A chart is a couple of dozen rows read as a whole — grouped by
 * type, in order — and paging it would break the one thing it is for. The
 * clients render it as a list with subheadings rather than as a table with a
 * pager.
 */
class AccountController extends ApiController
{
    public function __construct(private readonly AccountService $accounts) {}

    /**
     * The whole chart, in chart order.
     *
     * `?active=1` drops the retired ones, which is what a form wants to offer —
     * the same convention `expenses/categories` follows.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = $this->accounts->all($request->boolean('active'));

        return $this->collection(AccountResource::collection($accounts), $accounts);
    }

    /**
     * The five types, with what each one means.
     *
     * From the enum, so a form never hardcodes them and never has to know that
     * an expense grows on the debit side.
     */
    public function types(): JsonResponse
    {
        return $this->payload($this->accounts->types());
    }

    public function show(Account $account): JsonResponse
    {
        return $this->item(new AccountResource($account));
    }

    public function store(AccountRequest $request): JsonResponse
    {
        return $this->item(new AccountResource($this->accounts->create($request->toData())), status: 201);
    }

    /**
     * Edit an account.
     *
     * Everything is editable except the type, once anything has been posted —
     * `AccountService` explains why, and answers 422 with the reason rather
     * than silently restating every period the account appears in.
     */
    public function update(AccountRequest $request, Account $account): JsonResponse
    {
        return $this->item(new AccountResource($this->accounts->update($account, $request->toData())));
    }

    /**
     * Delete an account, or retire it.
     *
     * Two outcomes and the response says which: 204 for a row that is gone, and
     * the retired account itself for one that had postings and stays. On screen
     * those look nothing alike — vanished, versus greyed and still there — so
     * the client should not have to guess which happened.
     */
    public function destroy(Account $account): JsonResponse
    {
        $deleted = $this->accounts->delete($account);

        return $deleted
            ? $this->noContent()
            : $this->item(new AccountResource($account->refresh()), [
                'retired' => true,
                'message' => sprintf(
                    '%s has postings against it, so it was retired rather than deleted. '
                    .'Its history stays and it takes no new postings.',
                    $account->label(),
                ),
            ]);
    }
}

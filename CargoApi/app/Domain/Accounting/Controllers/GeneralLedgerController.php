<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\GeneralLedgerService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The General Ledger — the same postings as the journal, read by account.
 *
 * Read-only, all of it, and that is the design rather than a missing half.
 * Nothing is ever written *to* a ledger: it is what the journal looks like when
 * you sort it by account instead of by date, and the way to change a figure on
 * it is to write a journal entry. An endpoint here that accepted a posting
 * would be a second way into the books, and the two ways would disagree the
 * first time one of them forgot a rule.
 *
 * Three views, which are the three questions an office asks:
 *
 *   `GET ledger` — the chart with a balance against every account, grouped and
 *   subtotalled by type. Where somebody lands before they know which account
 *   they want.
 *
 *   `GET ledger/accounts/{account}` — one account's page: an opening balance,
 *   every posting in the range, and a running balance down the side.
 *
 *   `GET ledger/trial-balance` — the two columns, and whether they agree. The
 *   check that the books are whole.
 *
 * Dates are optional everywhere. Without them the answer is "everything so
 * far", which is what a balance means when nobody said as of when.
 */
class GeneralLedgerController extends ApiController
{
    public function __construct(private readonly GeneralLedgerService $ledger) {}

    /** The chart with balances — the ledger's index. */
    public function index(Request $request): JsonResponse
    {
        return $this->payload($this->ledger->summary(
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        ));
    }

    /**
     * One account's ledger page.
     *
     * The account is route-bound, which is scoped: an id from another company's
     * chart is a 404 before this method runs, because `SubstituteBindings` is
     * ordered after the tenant is in force (see `routes/api.php`).
     */
    public function show(Request $request, Account $account): JsonResponse
    {
        return $this->payload($this->ledger->forAccount(
            $account,
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        ));
    }

    /** Debits against credits, as of a date. */
    public function trialBalance(Request $request): JsonResponse
    {
        return $this->payload($this->ledger->trialBalance($this->date($request, 'as_of')));
    }

    /**
     * A date off the query string, or null.
     *
     * Parsed rather than passed through, so `from=2026-13-45` is a null range
     * — "everything so far" — instead of a SQL comparison against a string
     * nobody can read. A malformed date is not worth a 422 here: these are
     * reports, and the honest answer to an unparseable range is the whole
     * ledger rather than an error page.
     */
    private function date(Request $request, string $key): ?Carbon
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}

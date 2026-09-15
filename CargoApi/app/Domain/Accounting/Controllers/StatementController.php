<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Services\FinancialStatementService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The income statement and the balance sheet.
 *
 * Read-only, like the ledger and for the same reason: a statement is a view of
 * the journal, and the way to change a figure on one is to write an entry.
 *
 * Both take dates, and the difference between them is the point. An income
 * statement is a *period* — what was earned between two days — so it takes a
 * range. A balance sheet is a *moment*, so it takes one date and sums
 * everything up to it. Getting that backwards is the classic mistake, which is
 * why the two endpoints do not share a shape.
 *
 * Dates are optional. Without them the income statement covers everything ever
 * posted and the balance sheet stands as of today, which are the honest
 * defaults rather than a guess at a fiscal year nobody has configured.
 */
class StatementController extends ApiController
{
    public function __construct(private readonly FinancialStatementService $statements) {}

    /** What the business earned over a period. */
    public function income(Request $request): JsonResponse
    {
        return $this->payload($this->statements->incomeStatement(
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        ));
    }

    /** What it is worth at a moment. */
    public function balanceSheet(Request $request): JsonResponse
    {
        return $this->payload($this->statements->balanceSheet($this->date($request, 'as_of')));
    }

    /**
     * A date off the query string, or null.
     *
     * Parsed rather than passed through: `from=2026-13-45` becomes "no range"
     * — the whole ledger — instead of a SQL comparison against a string nobody
     * can read. These are reports, and the honest answer to an unreadable date
     * is everything rather than an error page.
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

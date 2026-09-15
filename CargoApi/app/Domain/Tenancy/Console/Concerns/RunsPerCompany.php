<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Console\Concerns;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Closure;

/**
 * Turns a command written for one company into one that serves all of them.
 *
 * The scheduled sweeps — releasing due trips, taking late ones overdue, chasing
 * unpaid invoices — are the platform's work, but each of them is *one
 * company's* work repeated. They read rows and then write more: a released trip
 * raises a notification, a backfill opens a ledger row. A command running with
 * no company in force would read across every firm on the install and then
 * throw on the first insert, because a row with no owner is refused.
 *
 * So they run once per company, each pass scoped and stamped, which also means
 * a failure is one company's failure. A firm whose data trips a bug does not
 * stop the sweep reaching the next one — it is reported and the loop carries
 * on, because the alternative is one bad row freezing everybody's overnight
 * work until somebody reads a log.
 */
trait RunsPerCompany
{
    /**
     * Run the command's work inside each company in turn.
     *
     * The closure gets the company, so output can say whose figures it is
     * reporting — "Released 3 trip(s)" is ambiguous on an install with eleven
     * hauliers on it.
     *
     * @param  Closure(Company): void  $work
     * @return int A command exit code: FAILURE if any company threw.
     */
    protected function eachCompany(Closure $work): int
    {
        $companies = Company::query()->active()->orderBy('name')->get();

        if ($companies->isEmpty()) {
            $this->info('No active companies. Nothing to do.');

            return self::SUCCESS;
        }

        $tenant = app(Tenant::class);
        $failed = 0;

        foreach ($companies as $company) {
            // Only worth a heading when there is more than one — a
            // single-company install should read exactly as it did before any
            // of this existed.
            if ($companies->count() > 1) {
                $this->line("<comment>{$company->name}</comment>");
            }

            try {
                $tenant->use($company, static fn () => $work($company));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  {$company->name}: {$e->getMessage()}");
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} of {$companies->count()} companies failed. The rest completed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

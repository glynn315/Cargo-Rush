<?php

declare(strict_types=1);

namespace App\Domain\Trip\Console;

use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use App\Domain\Trip\Services\TripService;
use Illuminate\Console\Command;

/**
 * Flip anything past its ETA to `overdue`.
 *
 * `TripService::reconcileOverdue()` was written to be "run on a schedule"
 * and had nothing running it, so lateness was only ever true at the moment
 * somebody happened to look. This is the caller that makes it true.
 */
class ReconcileOverdueTripsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:trips-overdue';

    protected $description = 'Mark trips past their ETA as overdue';

    public function handle(TripService $trips): int
    {
        return $this->eachCompany(function () use ($trips): void {
            $flagged = $trips->reconcileOverdue();

            $this->info($flagged === 0 ? 'Nothing overdue.' : "Flagged {$flagged} trip(s) overdue.");
        });
    }
}

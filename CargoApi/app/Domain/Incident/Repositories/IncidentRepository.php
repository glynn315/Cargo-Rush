<?php

declare(strict_types=1);

namespace App\Domain\Incident\Repositories;

use App\Domain\Incident\Models\Incident;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class IncidentRepository extends Repository
{
    protected function model(): string
    {
        return Incident::class;
    }

    public function query(): Builder
    {
        return Incident::query()
            ->with(['driver:id,name', 'vehicle:id,plate', 'trip:id,reference'])
            ->orderByDesc('occurred_at');
    }

    protected function searchable(): array
    {
        return ['reference', 'kind', 'place'];
    }

    /**
     * One driver's own write-ups, newest first — the handset's list.
     *
     * A method rather than a filter passed to `paginate()`, and the difference
     * matters: `applyFilters()` knows about `status` and `search` and quietly
     * ignores anything else, so a `driver_id` handed to it would not narrow the
     * query at all and every driver would read the whole log. A `where` that
     * cannot be dropped is the only safe way to express "only yours".
     */
    public function paginateForDriver(string $driverId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()->where('driver_id', $driverId)->paginate($perPage);
    }

    /** Anything not yet closed out — the sidebar badge and the KPI tile. */
    public function openCount(): int
    {
        return Incident::query()
            ->whereIn('status', [StatusValue::Pending->value, StatusValue::Active->value])
            ->count();
    }

    /**
     * How many incidents a crew member was involved in over a window.
     *
     * Every status counts, including the closed ones: a resolved incident still
     * happened, and a performance figure that quietly drops them would improve
     * every time the office finished its paperwork.
     */
    public function countForDriverBetween(string $driverId, Carbon $from, Carbon $to): int
    {
        return Incident::query()
            ->where('driver_id', $driverId)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Gps\Repositories;

use App\Domain\Gps\Models\GpsPing;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GpsPingRepository extends Repository
{
    protected function model(): string
    {
        return GpsPing::class;
    }

    public function query(): Builder
    {
        return GpsPing::query()->orderByDesc('recorded_at');
    }

    public function latestForTrip(string $tripId): ?GpsPing
    {
        return $this->query()->where('trip_id', $tripId)->first();
    }

    /** The run so far, oldest first, for average speed and the track line. */
    public function trailForTrip(string $tripId, int $limit = 200): Collection
    {
        return GpsPing::query()
            ->where('trip_id', $tripId)
            ->orderBy('recorded_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The drawable route, oldest first.
     *
     * Distinct from `trailForTrip` in two ways that matter to a map. It keeps
     * only the pings that have coordinates, because a polyline threaded
     * through the gaps would draw a line the truck never took. And it takes the
     * **last** N rather than the first: a ten-hour haul at a ping a minute is
     * six hundred points, and the first two hundred of those is the route out
     * of the yard rather than where the unit is now.
     *
     * Selected down to the four columns a point needs. A route query that pulls
     * whole models is the one on this table that gets slower every day the
     * fleet runs.
     */
    public function pathForTrip(string $tripId, int $limit = 500): Collection
    {
        return GpsPing::query()
            ->select(['id', 'lat', 'lng', 'speed_kph', 'recorded_at'])
            ->where('trip_id', $tripId)
            ->plotted()
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            // Newest-first off the index, then flipped: the map wants the route
            // in the order it was driven.
            ->sortBy('recorded_at')
            ->values();
    }
}

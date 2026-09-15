<?php

declare(strict_types=1);

namespace App\Domain\Gps\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * What the handset posts while it is moving.
 *
 * `location` and the coordinates are both here and they are not the same
 * thing. `location` is what a person reads — a place name where there is one,
 * and the formatted pair where there is not. `lat`/`lng` are what a map draws
 * and a bounding box filters on. The phone used to send only the first, having
 * stringified the second on the way out, which is why nothing in the system
 * could plot a route it had every point of.
 */
final class GpsPingData extends Data
{
    public function __construct(
        public readonly ?string $trip_id = null,
        public readonly ?string $location = null,
        public readonly ?float $lat = null,
        public readonly ?float $lng = null,
        public readonly ?int $speed_kph = null,
        public readonly ?string $heading = null,
        public readonly ?int $progress_pct = null,
        public readonly ?int $distance_done_m = null,
        public readonly ?string $recorded_at = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            trip_id: $attributes['trip_id'] ?? null,
            location: $attributes['location'] ?? null,
            lat: isset($attributes['lat']) ? (float) $attributes['lat'] : null,
            lng: isset($attributes['lng']) ? (float) $attributes['lng'] : null,
            speed_kph: isset($attributes['speed_kph']) ? (int) $attributes['speed_kph'] : null,
            heading: $attributes['heading'] ?? null,
            progress_pct: isset($attributes['progress_pct']) ? (int) $attributes['progress_pct'] : null,
            distance_done_m: isset($attributes['distance_done_m']) ? (int) $attributes['distance_done_m'] : null,
            recorded_at: $attributes['recorded_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'trip_id' => $this->trip_id,
            'location' => $this->location,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'speed_kph' => $this->speed_kph,
            'heading' => $this->heading,
            'progress_pct' => $this->progress_pct,
            'distance_done_m' => $this->distance_done_m,
            'recorded_at' => $this->recorded_at,
        ];
    }
}

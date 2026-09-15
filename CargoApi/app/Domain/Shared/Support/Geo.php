<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

/**
 * Distance between two pins, in one place.
 *
 * Straight-line and nothing more — a road network is a routing service this
 * system does not have, so every figure here is a floor on the real journey.
 * That is honest for the two things it is used for: a trip's distance when
 * nobody has measured it, and the order carriers appear in when a customer
 * asks who is near.
 *
 * Worked in PHP rather than in SQL. The install runs on SQLite as readily as
 * MySQL, and `radians()` is not something SQLite can be relied on to have —
 * a directory query that works on one developer's machine and returns an error
 * on another is worse than a query that sorts a few dozen rows in memory.
 * The set being sorted is companies, of which there are as many as there are
 * customers of this platform.
 */
final class Geo
{
    /** Mean earth radius. The one used everywhere in this codebase. */
    public const EARTH_RADIUS_M = 6_371_000;

    /** Great-circle distance between two points, in metres. */
    public static function metresBetween(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = $phi2 - $phi1;
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        // Clamped at 1: floating point can push the term a hair over it for
        // two points on opposite sides of the earth, and `asin` of 1.0000001
        // is NAN.
        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }

    /** The same, in kilometres, which is the unit a person reads. */
    public static function kmBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return self::metresBetween($lat1, $lng1, $lat2, $lng2) / 1000;
    }

    /**
     * A box around a point that certainly contains everything within `$km`.
     *
     * The cheap half of a proximity search: a `between` on two indexed columns
     * throws out most of the table before anything trigonometric happens. It
     * over-selects at the corners — a box is not a circle — which is why the
     * caller still measures what comes back.
     *
     * Longitude degrees narrow towards the poles, so the span is divided by the
     * cosine of the latitude. At the equator it is the same as latitude; the
     * clamp keeps it finite at a pole, where the whole idea stops meaning
     * anything.
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public static function boundingBox(float $lat, float $lng, float $km): array
    {
        $latSpan = $km / 111.045;
        $lngSpan = $km / max(0.01, 111.045 * cos(deg2rad($lat)));

        return [
            'min_lat' => max(-90, $lat - $latSpan),
            'max_lat' => min(90, $lat + $latSpan),
            'min_lng' => max(-180, $lng - $lngSpan),
            'max_lng' => min(180, $lng + $lngSpan),
        ];
    }
}

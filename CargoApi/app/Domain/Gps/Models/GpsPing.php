<?php

declare(strict_types=1);

namespace App\Domain\Gps\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Database\Factories\GpsPingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One position report from the handset. The driver app writes these; the web
 * GPS Dashboard only ever reads them (DESIGN.md section 5.4).
 */
class GpsPing extends Model
{
    /** @use HasFactory<GpsPingFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected $fillable = [
        'trip_id', 'location', 'lat', 'lng', 'speed_kph', 'heading',
        'progress_pct', 'distance_done_m', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            // Floats on the way out, not the decimal strings MySQL hands back.
            // A client plotting a point needs a number, and `"14.5536100"` in
            // JSON is a string that every one of them would have to remember to
            // parse.
            'lat' => 'float',
            'lng' => 'float',
            'speed_kph' => 'integer',
            'progress_pct' => 'integer',
            'distance_done_m' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Is this report a place on the earth, or only a status?
     *
     * Both exist and both are legitimate. A handset running the old build, or
     * one that reported before it had a fix, sends a description and a
     * progress figure with no coordinates — worth keeping, and not something a
     * map can draw. Everything that plots asks this first.
     */
    public function isPlotted(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** Only the pings a map can draw, oldest first — a route in order. */
    public function scopePlotted(Builder $query): Builder
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }

    /**
     * Read a coordinate pair back out of a legacy `location` string.
     *
     * Before this table had `lat`/`lng`, the handset formatted the position it
     * held into `location` — `"7.90000, 123.50000"` — and that string is all
     * the history on an existing install has. This is what recovers it, and it
     * lives on the model rather than inside the migration for two reasons: the
     * model is the thing that understands its own legacy format, and a rule
     * this fiddly should be testable without running a migration.
     *
     * **Deliberately strict.** `location` is also written by hand with real
     * place names, and "Km 9, Sasa" and "Warehouse 3, Bay 2" both contain a
     * comma and a number. A pattern loose enough to find coordinates in those
     * would invent a position off a street number and put a truck in the sea.
     * So: an optional sign, digits, a decimal point, digits — twice, with
     * nothing else in the string.
     *
     * Range-checked as well as shape-checked, because `"91.2, 200.5"` is a
     * well-formed pair and not a place on the earth.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function coordinatesFromLocation(?string $location): ?array
    {
        if ($location === null) {
            return null;
        }

        if (! preg_match('/^\s*(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/', $location, $matches)) {
            return null;
        }

        $lat = (float) $matches[1];
        $lng = (float) $matches[2];

        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }
}

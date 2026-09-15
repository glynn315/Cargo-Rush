<?php

declare(strict_types=1);

use App\Domain\Gps\Models\GpsPing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A position report becomes a position.
 *
 * `gps_pings.location` is a **string**, and the handset has been filling it by
 * formatting the coordinates it already had:
 *
 *     location: `${coords.lat.toFixed(5)}, ${coords.lng.toFixed(5)}`
 *
 * So the data was never missing — it was being flattened into display text on
 * the way out of the phone, in a column that elsewhere in this system holds
 * human place names ("Alabang exit", "Subic Freight Hub"). Everything a
 * tracking product does with a position was therefore impossible:
 *
 *   Nothing can be **queried** by it. Which units are inside this depot's
 *   fence, which are within 5 km of a breakdown, which crossed the provincial
 *   boundary — all of them are a bounding-box `where`, and none of them can be
 *   asked of a varchar.
 *
 *   Nothing can be **drawn** from it. Every ping of every trip is on file and
 *   the route cannot be plotted without parsing strings back into numbers and
 *   hoping every client formatted them the same way.
 *
 *   Nothing can be **indexed** on it.
 *
 * `decimal(10, 7)` matches `trips.origin_lat` — the same precision the map pins
 * already use, about 11mm, which is far finer than a phone's GPS and costs
 * nothing to keep.
 *
 * **Nullable, and staying that way.** Two reasons, and neither is laziness:
 * every row already on file predates this, and a handset running the old build
 * is still a truck on the road whose reports are worth having. A ping with no
 * coordinates is a status update rather than a position, which is exactly what
 * every one of them was until today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_pings', function (Blueprint $table): void {
            $table->decimal('lat', 10, 7)->nullable()->after('location');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');

            /**
             * The index the map reads by.
             *
             * Trip first, then time: drawing one run's route is
             * `where trip_id = ? order by recorded_at`, which is the query this
             * table exists to serve and the one that gets slower every day the
             * fleet runs. There is already a `(trip_id, recorded_at)` index; the
             * coordinates ride along so the path can be read without touching
             * the rows themselves.
             */
            $table->index(['trip_id', 'recorded_at', 'lat', 'lng'], 'gps_pings_path_index');
        });

        $this->backfillFromLocation();
    }

    public function down(): void
    {
        Schema::table('gps_pings', function (Blueprint $table): void {
            $table->dropIndex('gps_pings_path_index');
            $table->dropColumn(['lat', 'lng']);
        });
    }

    /**
     * Recover the coordinates the handset has been stringifying all along.
     *
     * Every ping written by the mobile app holds `"14.55361, 121.02472"` — a
     * decimal pair, a comma, optional spaces, and nothing else. Those parse
     * back exactly, so the history on this install is not lost to the change.
     *
     * Rows that do **not** match are left alone, and that is the important
     * half: `location` is also written by hand elsewhere with real place names,
     * and a pattern loose enough to find numbers inside "Km 9, Sasa" would
     * invent a position off a street number. The strictness lives in
     * `GpsPing::coordinatesFromLocation()`, which is where it can be tested
     * without running a migration.
     *
     * **In PHP, in chunks, rather than in SQL.** The first version of this did
     * the parse with `SUBSTRING_INDEX` and `REGEXP`, which is faster and works
     * only on MySQL — and this suite runs on SQLite, so the migration that
     * every test depends on would have failed on every machine that ran the
     * tests. A migration that cannot run on the same engine the tests use is a
     * migration nobody can prove correct. `chunkById` keeps memory flat, and
     * the `like '%,%'` pre-filter means a table full of place names is barely
     * touched: a coordinate pair always has a comma in it.
     */
    private function backfillFromLocation(): void
    {
        DB::table('gps_pings')
            ->select(['id', 'location'])
            ->whereNull('lat')
            ->where('location', 'like', '%,%')
            ->orderBy('id')
            ->chunkById(1000, function ($pings): void {
                foreach ($pings as $ping) {
                    $coordinates = GpsPing::coordinatesFromLocation($ping->location);

                    if ($coordinates === null) {
                        continue;
                    }

                    DB::table('gps_pings')->where('id', $ping->id)->update($coordinates);
                }
            });
    }
};

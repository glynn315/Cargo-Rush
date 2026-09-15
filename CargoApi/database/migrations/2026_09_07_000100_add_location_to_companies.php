<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the haulier actually is.
 *
 * `address` has been on this table since it was created, and it answers the
 * question a letter asks — not the one a customer standing in a warehouse asks,
 * which is *who can pick this up from here*. "Km 9, Sasa, Davao City" cannot be
 * sorted by distance, cannot be drawn on a map, and cannot be compared with the
 * pin on a pickup request. A pair of coordinates can do all three.
 *
 * That is the whole reason this exists: the customer app now opens a request by
 * listing the carriers near the load, with each one's yard on a small map, and
 * files the request against whichever the customer picks. A company with no pin
 * cannot appear on that list — which makes the pin the opt-in, rather than a
 * separate "list us" flag nobody would find. It is asked for at registration
 * and can be moved afterwards on the company's own endpoint.
 *
 * Nullable, and it stays that way. A firm that registered before this existed
 * is not broken; it simply keeps working with its own customers, exactly as it
 * did, until somebody drops a pin.
 *
 * `decimal(10, 7)` is the same shape the trip ends and the GPS pings use — near
 * enough a centimetre, and stored as a decimal rather than a float so two reads
 * of the same yard are the same number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // Together, because they are only ever read together: the carrier
            // directory prefilters on a box around the customer before it works
            // out a single distance.
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['latitude', 'longitude']);
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};

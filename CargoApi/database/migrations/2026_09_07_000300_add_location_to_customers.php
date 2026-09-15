<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the shipper's own store is.
 *
 * `customers` has never held a location. It did not need one while every
 * customer was typed in by an office that already knew where they were and rang
 * them about it — the record was a name, a contact and a rating.
 *
 * Two things now ask for it. A shipper who signs themselves up has to be shown
 * the hauliers *near them*, and the honest answer to "near what" is the place
 * they said their business is, not wherever the handset happens to be when they
 * open the app — a customer registering at home should still see the carriers
 * around their warehouse. And every pickup request starts from that same place
 * for most firms, so the pin is worth keeping rather than dropping again each
 * time.
 *
 * `address` is here for the same reason it is on `companies`: the pin is where
 * the truck goes and the address is what a person reads it back as. It is what
 * the request form fills "pick up from" with.
 *
 * All three nullable, and they stay that way. A customer the desk added over the
 * phone has no pin and needs none — their work goes to the haulier that added
 * them either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('address')->nullable()->after('contact');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['address', 'latitude', 'longitude']);
        });
    }
};

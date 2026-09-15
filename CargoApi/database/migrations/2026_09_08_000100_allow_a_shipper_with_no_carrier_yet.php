<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shipper who has signed up but not yet chosen anybody to carry a load.
 *
 * Registering used to mean picking a haulier in the same act: the sign-up form
 * asked for a carrier and a pin on the store, and the account was opened on
 * that carrier's books there and then. It no longer does. Who carries the load
 * and where the load is are both answered *per request*, on the request form,
 * because that is where they are actually known — a firm ships from three
 * warehouses and a firm that sends with the cheapest quote this week are both
 * ordinary, and neither is a thing to be decided once at sign-up and then
 * carried forever.
 *
 * Which leaves a state the schema had no room for: a login with no company.
 *
 * Two columns for it.
 *
 * `users.company_id` becomes nullable. It has been NOT NULL since companies
 * arrived and the invariant it enforced was a good one — every account belongs
 * to a haulier, so every query is scoped to one, so no firm can read another's
 * books. That invariant is *kept*, and this does not weaken it: null does not
 * mean "unscoped", it means **scoped to nothing**. `BindTenant` puts a company
 * in force that no row has, so every tenant-scoped read a company-less account
 * makes comes back empty and every tenant-scoped write is refused outright
 * (`BelongsToCompany`). The one account this applies to is a self-registered
 * shipper before their first request — see `users.chooses_carrier` — and it
 * stops applying the moment they file one: `ShipperAccounts::open()` writes the
 * company and the customer back onto the login, so from the first pickup
 * onwards the account is an ordinary customer of an ordinary haulier and every
 * path it takes is the path it always took.
 *
 * `users.phone` is the other half. The number the sign-up form asks for used to
 * go straight onto a `customers` row, because there was one to write it to. Now
 * there is not — the first `customers` row is opened later, at whichever carrier
 * they pick — so the number is kept on the login until there is somewhere to
 * copy it to. It is what the desk rings, and losing it would mean a customer
 * appearing on a haulier's books with an email address where the phone number
 * should be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // 40, matching `customers.contact` and the registration rule. A
            // phone number is not a formatted thing here: whatever somebody
            // types is what the desk dials.
            $table->string('phone', 40)->nullable()->after('email');
        });

        // The constraint has to come off before the column can change under
        // MySQL. SQLite has no statement for dropping one — a change there is a
        // table rebuild, which carries the constraint over — so it is skipped
        // rather than attempted.
        $rebuildsTables = DB::getDriverName() === 'sqlite';

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('company_id')->nullable()->change();
        });

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                // Unchanged from how it was added: deleting a company still
                // takes its people with it.
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Any account still waiting to choose a carrier has nothing to point at
        // and cannot be given one — picking a haulier on somebody's behalf is
        // the one thing this whole feature exists not to do. They are deleted,
        // which is what they are: a sign-up that never became a customer of
        // anybody.
        DB::table('users')->whereNull('company_id')->delete();

        $rebuildsTables = DB::getDriverName() === 'sqlite';

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('company_id')->nullable(false)->change();
        });

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};

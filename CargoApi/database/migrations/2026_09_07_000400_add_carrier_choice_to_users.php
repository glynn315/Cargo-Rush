<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of customer account, and the line between them.
 *
 * There are now two ways a shipper gets a login, and they mean different things:
 *
 *   **The office added them.** A haulier put the firm on its books and handed
 *   over credentials, exactly as it always has. That account belongs to that
 *   company. It sees that company's deliveries and that company's invoices,
 *   every request it files goes to that company, and it is shown no other
 *   haulier at all — not a list, not a map, not a name. The relationship is the
 *   product being sold; a portal that quietly offered the customer three
 *   competitors would be a strange thing for a fleet to pay for.
 *
 *   **They signed themselves up.** A firm with a pallet and no haulier arrived
 *   at the platform rather than at a company. They pick who carries their load
 *   from the carriers near them, and they can pick a different one next week —
 *   that choice is the whole reason they registered here rather than ringing
 *   somebody.
 *
 * `false` is the default, and deliberately the safe end: every account that
 * already exists was created by an office, and a column that defaulted the other
 * way would hand a haulier's own customers a list of its rivals in the next
 * deploy.
 *
 * On the login rather than on the `customers` row, because it is a property of
 * how the account came to exist. The same firm may quite reasonably be a
 * self-registered shipper on the platform *and* be on a haulier's books with a
 * separate login the desk created; those two accounts should behave differently,
 * and a flag on the shared firm record could not tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('chooses_carrier')->default(false)->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('chooses_carrier');
        });
    }
};

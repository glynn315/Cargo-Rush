<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One login, several hauliers.
 *
 * `users.customer_id` said a customer account belongs to exactly one `customers`
 * row, and a `customers` row belongs to exactly one company — so a shipper could
 * only ever transact with the haulier that put them on its books. That is the
 * right model for a firm the office typed in, and the wrong one now the customer
 * app lists the carriers near a load and lets the customer pick.
 *
 * The `customers` row stays per company, and that is deliberate rather than a
 * limitation: a rating, a VAT treatment, a withholding flag and a history of
 * unpaid invoices are one haulier's opinion of a shipper, not a fact about them.
 * Two carriers hauling for the same firm keep two sets of books about it, as
 * they would if they had never met.
 *
 * What spans them is the **login**. This table is the fan-out: the accounts a
 * person signs in with, and every `customers` row they act for. Filing a request
 * with a new carrier creates that carrier's row and links it here, so the desk
 * at the far end sees an ordinary customer with an ordinary pending trip, and
 * the tenant scope never has to be lifted for it — the portal reads each
 * carrier's books inside that carrier's tenancy, one at a time.
 *
 * `users.customer_id` is kept, and keeps its meaning: the *home* record, the one
 * a login was created against. It is what a single-carrier install has always
 * used and what every existing query still reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_user', function (Blueprint $table): void {
            $table->id();

            // No `company_id`, and no tenant scope. This is the one table in
            // the system that is deliberately *about* crossing companies — a
            // row here says "this person may act for that firm's account", and
            // the firm's company is on the `customers` row it points at.
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // A link is a fact, not a quantity: linking twice is the same link.
            $table->unique(['customer_id', 'user_id']);
            $table->index('user_id');
        });

        // Everybody who already had a home record keeps it, as a link. Without
        // this the portal would read no accounts at all for the customers
        // already on the books, which is a worse outcome than the feature not
        // existing.
        DB::table('users')
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->select(['id', 'customer_id'])
            ->chunk(500, function ($users): void {
                DB::table('customer_user')->insertOrIgnore(
                    $users->map(static fn ($user): array => [
                        'customer_id' => $user->customer_id,
                        'user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Money arriving, as its own record.
 *
 * Settling an invoice used to be a status flip and a `paid_at` stamp. That
 * models exactly one way of being paid — the whole amount, once, from nowhere
 * in particular — and it is not how customers pay. What actually happens:
 *
 *   **A part payment.** ₱50,000 against a ₱120,000 invoice, with the rest
 *   next month. The old model had two states and no way to hold the third.
 *
 *   **One cheque against four invoices.** A customer settles the month, not a
 *   document. With payment as a column on the invoice there was nowhere to put
 *   the cheque, so it had to be split by hand into four fictions.
 *
 *   **A reference somebody can chase.** A cheque number, a bank transfer
 *   reference, the date it cleared. None of it had a column, so reconciling
 *   against a bank statement meant matching amounts by eye.
 *
 * Two tables, because a payment and its application to a document are
 * genuinely different things: the money is one event, and where it was put may
 * be several. That split is what makes both cases above expressible, and it is
 * the same shape any ledger uses.
 *
 * `invoices.paid_at` and the `paid` status stay exactly where they are. They
 * are now **derived from the allocations** rather than typed — the same rule
 * this system already applies to a ledger total — so every screen that reads
 * them keeps working and none of them can drift from the payments underneath.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('company_id');

            /**
             * Who paid, or who was paid.
             *
             * Null on a payable's payment — money going out to a supplier who
             * is not a customer of ours, named on the invoice's `payee`
             * instead. The same asymmetry `invoices` already carries.
             */
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();

            // `INV` for money in, `BILL` for money out. The direction is on the
            // payment as well as on the documents it settles, because a payment
            // is filed and reported on before anybody opens its allocations.
            $table->string('direction')->default('receivable');

            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');

            // The day the money moved, which is not the day somebody typed it
            // in. Every collections figure is dated from this.
            $table->date('paid_on');

            // cash / cheque / bank_transfer / online — free-ish text rather
            // than an enum, because the list is a business's own and grows.
            $table->string('method')->default('bank_transfer');
            /**
             * The cheque number, the transfer reference, the deposit slip.
             *
             * The single most useful column here: it is what a bank statement
             * is matched against, and its absence is why reconciliation was
             * previously done by comparing amounts and hoping.
             */
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            // The two queries this table serves: a customer's payment history,
            // and everything that came in over a period.
            $table->index(['customer_id', 'paid_on']);
            $table->index(['direction', 'paid_on']);
        });

        /**
         * Where a payment was put.
         *
         * The join that makes both awkward cases work: many rows per payment
         * is one cheque across four invoices, many rows per invoice is a
         * document paid off in instalments.
         */
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('company_id');

            // Cascading, unlike most: an allocation with no payment is not a
            // record of anything. Deleting the payment is what un-applies it.
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();

            // How much of this payment went against this invoice. Not the
            // payment's total, and not the invoice's — the part where they meet.
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->index('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            /**
             * One row per pair.
             *
             * A payment applied to the same invoice twice is a double count,
             * and it is the kind that balances — the payment total still looks
             * right while the invoice reads as overpaid. The service adds to
             * an existing row rather than inserting a second.
             */
            $table->unique(['payment_id', 'invoice_id']);
            $table->index(['invoice_id']);
        });

        // Every invoice already marked paid was paid in full, on `paid_at`, by
        // some means nobody recorded. Writing that as a payment keeps the two
        // views consistent from the first day — otherwise the Billing page
        // would show a paid invoice with no payment behind it, which reads as
        // a bug rather than as history.
        $this->backfillSettledInvoices();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }

    private function backfillSettledInvoices(): void
    {
        DB::table('invoices')
            ->whereNotNull('paid_at')
            ->orderBy('id')
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $paymentId = (string) Str::ulid();
                    $paidAt = $invoice->paid_at;

                    DB::table('payments')->insert([
                        'id' => $paymentId,
                        'company_id' => $invoice->company_id,
                        'customer_id' => $invoice->customer_id,
                        'direction' => $invoice->direction,
                        // The gross the document asked for, less anything the
                        // customer withheld — which is what would have arrived.
                        'amount_cents' => max(0, (int) $invoice->amount_cents - (int) $invoice->withholding_cents),
                        'currency' => $invoice->currency,
                        'paid_on' => substr((string) $paidAt, 0, 10),
                        'method' => 'bank_transfer',
                        // Said plainly rather than invented. Nobody recorded a
                        // reference for these, and a made-up one would be
                        // matched against a bank statement and fail.
                        'reference' => null,
                        'notes' => 'Recorded before payments were kept separately.',
                        'created_at' => $paidAt,
                        'updated_at' => $paidAt,
                    ]);

                    DB::table('payment_allocations')->insert([
                        'id' => (string) Str::ulid(),
                        'company_id' => $invoice->company_id,
                        'payment_id' => $paymentId,
                        'invoice_id' => $invoice->id,
                        'amount_cents' => max(0, (int) $invoice->amount_cents - (int) $invoice->withholding_cents),
                        'created_at' => $paidAt,
                        'updated_at' => $paidAt,
                    ]);
                }
            });
    }
};

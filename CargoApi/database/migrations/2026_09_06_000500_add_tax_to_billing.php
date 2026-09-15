<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice total becomes the number somebody actually pays.
 *
 * Until now an invoice carried one figure — `amount_cents` — and a Philippine
 * freight invoice carries four. A haul priced at ₱10,000 is billed at ₱11,200
 * with VAT, the customer keeps back ₱224 in withholding tax, and ₱10,976
 * arrives in the bank. The system knew only the ₱10,000, which meant the
 * document it printed was not one a VAT-registered customer could accept and
 * the figure it expected never matched the payment.
 *
 * Four columns rather than one, and each is stored rather than derived:
 *
 *     net_amount_cents      the haul itself, the taxable base
 *     vat_cents             charged on, remitted by us
 *     amount_cents          net + VAT — what the document says. Unchanged in
 *                           meaning, which is why every existing reader of it
 *                           keeps working.
 *     withholding_cents     kept back by the customer, remitted by them
 *
 * **Stored, not computed on read**, for the same reason a trip's price is
 * stored: an invoice is a promise made on a date. Rates change by statute, a
 * company registers for VAT, a customer becomes a withholding agent — and none
 * of that may alter a document somebody is already holding. The rates that
 * applied are frozen onto the row beside the figures they produced.
 *
 * Existing invoices are backfilled as **untaxed**: net equals the amount, VAT
 * and withholding are zero, and the rates are zero. That is the truthful
 * record — those documents were issued without tax on them, and retroactively
 * adding 12% would make every one of them disagree with the copy a customer
 * has in a folder.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Whether *this* company charges VAT at all.
         *
         * Not every haulier is VAT-registered — below the threshold a business
         * files percentage tax instead and issues invoices with no VAT line.
         * A platform-wide rate with no per-company switch would put a VAT line
         * on the invoices of a company with no authority to charge it.
         */
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('tin', 20)->nullable()->after('code');
            $table->boolean('vat_registered')->default(true)->after('tin');
            // Null means "whatever the platform default is", so a rate change
            // in statute reaches every company that never overrode it.
            $table->unsignedSmallInteger('vat_rate_bp')->nullable()->after('vat_registered');
        });

        /**
         * And whether *this* customer withholds, or is exempt.
         *
         * The two questions are independent and both are the customer's. A
         * government agency or a large corporate withholds; a small trader
         * does not. An exporter or a PEZA locator is zero-rated; a co-operative
         * may be exempt. Guessing either produces an invoice the customer's
         * accounts payable will reject.
         */
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('tin', 20)->nullable()->after('contact');
            /**
             * `vatable`, `zero_rated` or `exempt`.
             *
             * The difference between the last two matters to the filing even
             * though both put ₱0 on the invoice, which is why this is three
             * values rather than a boolean.
             */
            $table->string('vat_treatment')->default('vatable')->after('tin');
            $table->boolean('withholds_tax')->default(false)->after('vat_treatment');
            $table->unsignedSmallInteger('withholding_rate_bp')->nullable()->after('withholds_tax');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            // Nullable only so the backfill below can fill them; made
            // non-nullable at the end, because an invoice with no taxable base
            // is a document that cannot be added up.
            $table->unsignedBigInteger('net_amount_cents')->nullable()->after('amount_cents');
            $table->unsignedBigInteger('vat_cents')->default(0)->after('net_amount_cents');
            $table->unsignedBigInteger('withholding_cents')->default(0)->after('vat_cents');

            // The rates as they stood on the day. Frozen for the same reason
            // the figures are.
            $table->unsignedSmallInteger('vat_rate_bp')->default(0)->after('withholding_cents');
            $table->unsignedSmallInteger('withholding_rate_bp')->default(0)->after('vat_rate_bp');
            $table->string('vat_treatment')->default('vatable')->after('withholding_rate_bp');
        });

        // Every document already issued was issued without tax. Saying so is
        // the honest backfill; inventing a VAT line for it is not.
        DB::table('invoices')->whereNull('net_amount_cents')->update([
            'net_amount_cents' => DB::raw('amount_cents'),
            'vat_cents' => 0,
            'withholding_cents' => 0,
            'vat_rate_bp' => 0,
            'withholding_rate_bp' => 0,
        ]);

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('net_amount_cents')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'net_amount_cents', 'vat_cents', 'withholding_cents',
                'vat_rate_bp', 'withholding_rate_bp', 'vat_treatment',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['tin', 'vat_treatment', 'withholds_tax', 'withholding_rate_bp']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['tin', 'vat_registered', 'vat_rate_bp']);
        });
    }
};

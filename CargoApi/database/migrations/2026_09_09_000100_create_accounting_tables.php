<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The books proper: a chart of accounts, a general journal, a general ledger.
 *
 * Everything the money modules have kept until now is *operational*. The daily
 * ledger (`ledger_entries`) is the workbook's truck sheet — income and five
 * expense columns per unit per day. `expenses` are the categorised lines that
 * sheet had no room for, `invoices` are what customers owe, `payments` are what
 * has arrived. Each answers one question well, and none of them is accounting:
 * nothing in that set can produce a trial balance, because nothing in it has
 * two sides.
 *
 * These three tables are the double-entry layer over the top.
 *
 *   `accounts` — the chart of accounts. Every posting names one, and every
 *   account has a type (asset, liability, equity, income, expense) which is
 *   what decides whether a debit raises or lowers it. Seeded with a chart a
 *   haulier can actually use, and extendable, because the one thing every
 *   fleet's accountant wants is an account of their own.
 *
 *   `journal_entries` — the general journal. One row per transaction: a date,
 *   a reference in its own series, a memo, an accounting category, and a
 *   status. Nothing about *amounts* lives here on purpose; a transaction has
 *   no single amount, it has sides.
 *
 *   `journal_lines` — the sides. Each names an account and carries a debit or
 *   a credit, never both. An entry's debits must equal its credits, and
 *   `JournalService` refuses to post one that does not: an unbalanced entry is
 *   not a transaction that needs fixing later, it is not a transaction.
 *
 * The general ledger is not a table. It is these two read by account and by
 * date, with a running balance — see `GeneralLedgerService`. Keeping a balance
 * column on `accounts` would be a second version of the truth, and the day it
 * drifted from the lines nobody could say which was right.
 *
 * ## Why the money columns are what they are
 *
 * `bigInteger` centavos, matching every other money column in this system
 * (DESIGN.md section 7.1). Unsigned would have been the tighter choice for a
 * debit or a credit — neither is ever negative, since the direction is the
 * column you are in — but signed matches the rest of the schema and leaves the
 * door open for a correcting line the accountant writes as a negative debit
 * rather than as a credit, which is how some offices work.
 *
 * ## Posting, voiding, and what is never done
 *
 * A `draft` entry can be edited and deleted: it is somebody's work in
 * progress. A `posted` one cannot be touched — that is the whole point of
 * posting, and an audit trail that can be quietly rewritten is not one. What a
 * posted entry can be is **voided**, which leaves the row and its lines exactly
 * where they are and excludes them from every balance, so the history still
 * shows that somebody posted it and that somebody took it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            /**
             * The company whose chart this is.
             *
             * Not null and constrained from the start, unlike the tables that
             * predate tenancy and had to be backfilled into it: these three are
             * born inside it. Stamped by `BelongsToCompany` from the company in
             * force, never from a payload, and filtered by the same global
             * scope every other tenant table gets.
             */
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /**
             * The account number the office quotes — 1010, 4000, 5300.
             *
             * A string rather than an integer, because a chart of accounts is
             * numbered in ranges and offices sub-number them: 5300, then
             * 5300-01 for the same expense at a second depot. Unique per
             * company, so two hauliers on one install both number their cash
             * account 1010 and neither collides.
             */
            $table->string('code', 20);
            $table->string('name');
            /**
             * asset, liability, equity, income, expense.
             *
             * Not a free-form label: it decides the sign of every balance this
             * account will ever report, which side of a trial balance it lands
             * on, and whether it belongs to the balance sheet or the income
             * statement. See `AccountType`, which owns those three answers.
             */
            $table->string('type');
            /**
             * A sub-heading within the type — "Current asset", "Cost of
             * services", "Operating expense".
             *
             * Reporting only, and optional: it groups the rows on a statement
             * without ever changing what they add up to.
             */
            $table->string('group')->nullable();
            $table->string('description')->nullable();
            /**
             * A seeded account, which the office may rename but not delete.
             *
             * The seeded chart is what the rest of the system posts to when it
             * starts posting automatically, so an install that deleted its cash
             * account would break in a way nobody would connect to a tidy-up
             * six months earlier.
             */
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'status']);
            // Two hauliers on one install both number their cash account 1010,
            // and neither collides.
            $table->unique(['company_id', 'code']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /** `JV-0001`, in its own series per company. */
            $table->string('reference');
            /** The day the transaction belongs to. A date, like the ledger. */
            $table->date('entry_date');
            /**
             * The accounting category — what kind of transaction this is.
             *
             * Operations, payroll, fuel, maintenance, billing, collection,
             * financing, tax, adjustment or opening balance. It is not the
             * accounts (those are on the lines, and one entry usually touches
             * two categories' worth of them) and it is not a second chart: it
             * is the answer to "why was this written", which is the question
             * somebody scrolling the journal in March is actually asking. It
             * also gives the journal a grouping that survives a change to the
             * chart of accounts. See `JournalCategory`.
             */
            $table->string('category');
            /** What it was for, in a sentence. The journal's own narration. */
            $table->string('memo');
            /**
             * Where it came from.
             *
             * `manual` is somebody at a keyboard. The others are for entries
             * raised from an operational document — an invoice issued, a
             * payment received, a day's ledger sheet closed — so a posting can
             * always be traced back to the thing that caused it, and so the
             * same document can never be posted twice.
             */
            $table->string('source')->default('manual');
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();

            /** draft → posted → (void). See the note at the top of this file. */
            $table->string('status')->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            /** Why it was taken back. Required by the service, not by the column. */
            $table->string('void_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'category']);
            // One posting per source document, ever. A double-posted invoice is
            // money counted twice, and the index is a better guarantee of that
            // than every caller remembering to check.
            $table->unique(['company_id', 'source_type', 'source_id']);
            $table->unique(['company_id', 'reference']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            // Cascading, unlike most of this schema, and deliberately: a line
            // without its entry is not a record of anything. Deleting a draft
            // takes its lines with it, and a posted entry cannot be deleted at
            // all.
            $table->foreignUlid('journal_entry_id')->constrained()->cascadeOnDelete();
            // Restricted: an account with postings against it cannot be
            // deleted, because the postings are the history. `AccountService`
            // retires it instead — the same rule `expense_categories` follows.
            $table->foreignUlid('account_id')->constrained()->restrictOnDelete();

            /** The order the accountant wrote them in, debits first by custom. */
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            /** A note on this side alone, where the entry's memo is not enough. */
            $table->string('memo')->nullable();

            /**
             * What this side of the transaction was about, where there is
             * something to point at.
             *
             * All nullable and all independent: a fuel posting names a truck, a
             * receivable names a customer, a payroll line names neither. They
             * are what lets the ledger answer "show me this truck's costs"
             * without a second set of books.
             */
            $table->foreignUlid('truck_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'account_id']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};

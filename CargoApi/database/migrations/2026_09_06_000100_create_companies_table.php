<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant. One haulier, and everything that belongs to it.
 *
 * Until now the system was one company's: a single set of trucks, one roster,
 * one ledger, and a `users` table where every row could see all of it. That is
 * the right shape for an install sitting in one office, and the wrong one the
 * moment two firms sign in to the same deployment.
 *
 * This table is what the next migration hangs every other table off. The rules
 * that follow from it, and that the rest of the system relies on:
 *
 *   A company is created by **registering** it (`POST /api/v1/register`), not
 *   by a developer. Registration also creates the first account — the person
 *   who registered — because a company nobody can sign in to is not a company
 *   yet, it is a row.
 *
 *   `code` is the company's handle: a slug derived from the name, unique across
 *   the whole system. It is what a support conversation names, what appears in
 *   an export filename, and what would become a subdomain if this install ever
 *   wants one. It is deliberately *not* what somebody types to sign in — an
 *   email address belongs to exactly one company, so the account itself says
 *   which company to open, and the login form stays two fields.
 *
 *   `status` gates the sign-in, not the data. Suspending a company stops its
 *   people getting in; it does not delete a single trip, invoice or peso. The
 *   difference matters when the suspension turns out to be a billing mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The trading name, as it appears on the company's own paperwork.
            $table->string('name');
            // The slug. Unique system-wide, because it identifies the tenant
            // rather than describing it.
            $table->string('code')->unique();

            // Who to contact about the account itself — the person who
            // registered, unless somebody has since changed it. Kept apart
            // from `users` on purpose: the billing contact for a company
            // outlives whichever employee happens to hold an account today.
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('address')->nullable();

            /**
             * From the shared status vocabulary (DESIGN.md section 1) — so
             * `active`, and `inactive` for a company that has been suspended.
             * Not a word of its own: the clients map these strings to colours,
             * and a value only this table used would render as no pill at all.
             *
             * Checked at sign-in and nowhere else. A suspended company's rows
             * stay exactly where they are and come back untouched when it is
             * reactivated.
             */
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

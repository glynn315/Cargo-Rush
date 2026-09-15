<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own mark.
 *
 * Both shells now name the company beside the Cargo Rush wordmark, because a
 * screen that says only "Cargo Rush" cannot answer *whose fleet am I looking
 * at*. A name in 12px answers it on the second read; a logo answers it on the
 * first, which is what a person glancing at a sidebar actually does.
 *
 * Only the **path** is stored and the URL is derived on read — the same rule as
 * a staff photograph and proof of delivery, for the same reason: moving the
 * install must not leave every company pointing at a host that no longer
 * exists.
 *
 * The file behind the path is always a **64×64 PNG**, normalised on upload
 * rather than trusted from the client. See `LogoStore` for why that number and
 * why it is enforced server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Nullable, and it stays that way. A company with no logo is
            // ordinary — most register without one and add it later — and the
            // clients fall back to the company's initials, exactly as the user
            // chip already does for an account with no avatar.
            $table->string('logo_path')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};

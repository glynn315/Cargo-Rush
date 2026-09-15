<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Hangs every table that belongs to a haulier off the company that owns it.
 *
 * The column is on all of them, including the ones reachable through a parent —
 * a `gps_pings` row could in principle be found through its trip, and a
 * `delivery_logs` row through the same trip again. It carries its own
 * `company_id` regardless, and the reason is not tidiness:
 *
 *   Every module queries its own table directly (`DeliveryLogRepository` starts
 *   at `delivery_logs`, not at `trips`). Scoping those through a relation would
 *   mean each repository remembering to join, and the day one of them forgets
 *   is the day a firm reads another firm's deliveries. A column on the row
 *   itself is filtered by one global scope that no query can opt out of by
 *   accident.
 *
 * `permissions` and `nav_items` deliberately have no company. They are the
 * product's own vocabulary — a permission is only real if code checks for it,
 * and the navigation is the list of modules this application has. Neither is
 * any one company's to redefine. `roles` and `positions` *are* per company,
 * because what a firm calls its jobs and which of them opens the ledger is
 * exactly the thing every office does differently.
 *
 * Uniqueness moves with the data. A plate, a licence number, a payroll number
 * and a trip reference are unique *within a company* now — two hauliers both
 * running a truck called "Truck 1", and both starting their references at
 * CR-24801, is the normal case rather than a collision. The one that stays
 * global is `users.email`: an address identifies exactly one account, which is
 * what lets the login form stay two fields and still know which company to
 * open.
 *
 * Existing rows are backfilled into a single company so nothing is orphaned and
 * the accounts already on this install keep working — see `defaultCompany()`.
 */
return new class extends Migration
{
    /**
     * Every tenant-owned table, and the unique indexes that become per-company.
     *
     * @var array<string, string[]>
     */
    private const TENANT_TABLES = [
        // Identity and access. `users.email` is absent from the list on
        // purpose: it stays unique system-wide.
        'users' => [],
        'roles' => ['key'],
        'positions' => ['key'],

        // Assets.
        'drivers' => ['licence_no'],
        'vehicles' => ['plate'],
        'customers' => [],
        'maintenance_jobs' => [],

        // Operations.
        'trips' => ['reference'],
        'gps_pings' => [],
        'dispatch_records' => [],
        'delivery_logs' => [],
        'incidents' => [],
        'inspections' => [],

        // Money.
        'fuel_records' => [],
        'fuel_budgets' => ['date'],
        'invoices' => ['number'],
        'trucks' => [],
        'ledger_entries' => [],
        'expense_categories' => ['key'],
        'expenses' => [],

        // The rate card.
        'pricing_zones' => ['code'],
        'pricing_brackets' => [],
        'diesel_prices' => ['effective_on'],

        // People.
        'employees' => ['employee_no'],
        'applicants' => [],
        'leave_requests' => [],
        'undertime_requests' => [],

        // Support.
        'notification_items' => [],
    ];

    /**
     * `incidents.reference` is unique but is not listed above with the others.
     *
     * It is here instead because the column shares its name with
     * `trips.reference`, and listing both under a single 'reference' key would
     * be a silent overwrite in the array. Kept explicit rather than clever.
     *
     * @var array<string, string[]>
     */
    private const EXTRA_SCOPED_UNIQUES = [
        'incidents' => ['reference'],
    ];

    public function up(): void
    {
        // 1. The column, nullable for now — there is nothing to put in it yet.
        foreach (array_keys(self::TENANT_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->ulid('company_id')->nullable()->after('id');
            });
        }

        // 2. Somewhere for the rows already here to live.
        $companyId = $this->defaultCompany();

        // 3. Backfill. Every row on this install belonged to one company; it
        //    just had no way to say so.
        foreach (array_keys(self::TENANT_TABLES) as $table) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        // 4. Required from here on. A row with no company is a row no scope can
        //    filter, so the database refuses one rather than letting it become
        //    invisible to its owner and visible to everybody else.
        foreach (array_keys(self::TENANT_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->ulid('company_id')->nullable(false)->change();
                $blueprint->index('company_id');
                $blueprint->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        // 5. Uniqueness is now per company.
        foreach ($this->scopedUniques() as $table => $columns) {
            foreach ($columns as $column) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropUnique([$column]);
                    $blueprint->unique(['company_id', $column]);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->scopedUniques() as $table => $columns) {
            foreach ($columns as $column) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropUnique(['company_id', $column]);
                    $blueprint->unique([$column]);
                });
            }
        }

        foreach (array_keys(self::TENANT_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['company_id']);
                $blueprint->dropIndex(['company_id']);
                $blueprint->dropColumn('company_id');
            });
        }
    }

    /**
     * The tables and columns whose unique index becomes `(company_id, column)`.
     *
     * @return array<string, string[]>
     */
    private function scopedUniques(): array
    {
        $uniques = array_filter(self::TENANT_TABLES);

        foreach (self::EXTRA_SCOPED_UNIQUES as $table => $columns) {
            $uniques[$table] = array_merge($uniques[$table] ?? [], $columns);
        }

        return $uniques;
    }

    /**
     * The company every existing row is filed under.
     *
     * Its name comes from the environment so an install that is somebody's
     * actual business ends up saying so, rather than being called "Cargo Rush"
     * forever because that is what the software is called. A fresh install gets
     * the same row: the seeded accounts need a company to belong to, and one
     * created here is one the seeders do not have to invent.
     */
    private function defaultCompany(): string
    {
        $name = (string) env('DEFAULT_COMPANY_NAME', 'Cargo Rush');
        $code = Str::slug((string) env('DEFAULT_COMPANY_CODE', $name)) ?: 'cargo-rush';

        $existing = DB::table('companies')->where('code', $code)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::ulid();

        DB::table('companies')->insert([
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'contact_email' => env('DEFAULT_COMPANY_EMAIL'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};

<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\JournalCategory;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The two statements the books exist to produce.
 *
 * The trial balance proves the ledger is whole. These say what it *means* — and
 * the properties worth defending are the ones that make them trustworthy rather
 * than merely printable:
 *
 *   **They agree with the ledger.** Both are built from the same
 *   `balances()` primitive the trial balance uses, so a figure here can never
 *   contradict the ledger under it. The first time an accountant finds a report
 *   that disagrees with its own ledger, nothing on the screen is worth anything.
 *
 *   **A period means a period.** Income and expenses start again, so the income
 *   statement takes a range and honours it. Assets and liabilities carry over,
 *   so the balance sheet takes a date and sums everything up to it.
 *
 *   **The sheet balances, visibly.** There is no period close in this system,
 *   so the result to date is carried as a named equity line rather than folded
 *   into an account nobody posted to. Naming it is what keeps the arithmetic
 *   checkable.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->accountant = User::where('email', 'accounts@cargorush.ph')->firstOrFail();
    $this->driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();

    $account = fn (string $code): Account => Account::query()->where('code', $code)->firstOrFail();

    $this->cash = $account('1010');
    $this->receivable = $account('1100');
    $this->payable = $account('2010');
    $this->revenue = $account('4010');
    $this->fuel = $account('5010');
    $this->office = $account('5200');

    /** A posted entry. Everything below is built out of these. */
    $this->post = fn (array $lines, array $overrides = []) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/accounting/journal', [
            'entry_date' => now()->toDateString(),
            'category' => JournalCategory::Operations->value,
            'memo' => 'Test entry',
            'status' => 'posted',
            'lines' => $lines,
            ...$overrides,
        ])->assertCreated();

    /** A haul billed: revenue earned, money owed to us. */
    $this->bill = fn (int $amount, array $overrides = []) => ($this->post)([
        ['account_id' => $this->receivable->id, 'side' => 'debit', 'amount_cents' => $amount],
        ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount_cents' => $amount],
    ], $overrides);

    /** Diesel bought on credit: the cost of actually hauling. */
    $this->spendOnFuel = fn (int $amount, array $overrides = []) => ($this->post)([
        ['account_id' => $this->fuel->id, 'side' => 'debit', 'amount_cents' => $amount],
        ['account_id' => $this->payable->id, 'side' => 'credit', 'amount_cents' => $amount],
    ], $overrides);

    $this->income = fn (array $query = []) => $this->actingAs($this->accountant)
        ->getJson('/api/v1/accounting/statements/income?'.http_build_query($query))
        ->assertOk()->json('data');

    $this->sheet = fn (array $query = []) => $this->actingAs($this->accountant)
        ->getJson('/api/v1/accounting/statements/balance-sheet?'.http_build_query($query))
        ->assertOk()->json('data');
});

describe('the income statement', function (): void {
    it('reads revenue, then the cost of hauling, then everything else', function (): void {
        ($this->bill)(10_000_00);
        ($this->spendOnFuel)(4_000_00);
        // An office cost, which is not the cost of providing the service.
        ($this->post)([
            ['account_id' => $this->office->id, 'side' => 'debit', 'amount_cents' => 1_000_00],
            ['account_id' => $this->cash->id, 'side' => 'credit', 'amount_cents' => 1_000_00],
        ]);

        $statement = ($this->income)();

        expect($statement['revenue']['total_cents'])->toBe(10_000_00)
            ->and($statement['expenses']['total_cents'])->toBe(5_000_00)
            // The gap that says whether the hauling itself pays.
            ->and($statement['cost_of_services_cents'])->toBe(4_000_00)
            ->and($statement['gross_profit_cents'])->toBe(6_000_00)
            // `toEqual`, because a whole percentage comes back over JSON as an
            // integer and 60 is 60.0.
            ->and($statement['gross_margin_pct'])->toEqual(60)
            // And the gap that says whether the business does.
            ->and($statement['net_income_cents'])->toBe(5_000_00)
            ->and($statement['net_margin_pct'])->toEqual(50);
    });

    it('groups the accounts under their own sub-headings', function (): void {
        ($this->bill)(10_000_00);
        ($this->spendOnFuel)(4_000_00);

        $groups = collect(($this->income)()['expenses']['groups']);

        expect($groups->pluck('group')->all())->toBe(['Cost of services'])
            ->and($groups->first()['accounts'][0]['code'])->toBe('5010')
            ->and($groups->first()['total_cents'])->toBe(4_000_00);
    });

    it('leaves accounts with nothing on them off the page', function (): void {
        ($this->bill)(10_000_00);

        $statement = ($this->income)();

        // Forty seeded expense accounts, none of them posted to. A statement
        // padded with zeroes hides the lines that matter.
        expect($statement['expenses']['groups'])->toBeEmpty()
            ->and($statement['revenue']['groups'])->toHaveCount(1);
    });

    it('means the range it was given', function (): void {
        ($this->bill)(10_000_00, ['entry_date' => now()->subMonths(2)->toDateString()]);
        ($this->bill)(3_000_00);

        $thisMonth = ($this->income)(['from' => now()->startOfMonth()->toDateString()]);

        expect($thisMonth['revenue']['total_cents'])->toBe(3_000_00)
            ->and($thisMonth['range']['from'])->toBe(now()->startOfMonth()->toDateString());

        // And with no range, everything so far.
        expect(($this->income)()['revenue']['total_cents'])->toBe(13_000_00);
    });

    it('reports no margin rather than a wrong one when there is no revenue', function (): void {
        ($this->spendOnFuel)(4_000_00);

        $statement = ($this->income)();

        expect($statement['net_income_cents'])->toBe(-4_000_00)
            // Dividing by zero revenue would be a percentage nobody could read.
            ->and($statement['net_margin_pct'])->toBeNull()
            ->and($statement['gross_margin_pct'])->toBeNull();
    });

    /**
     * The cost-of-services group is the office's own label, read from config.
     *
     * An install that renames it gets a null gross profit rather than a wrong
     * one — a margin computed from the wrong half of the expenses is worse than
     * no margin at all.
     */
    it('declines to guess a gross profit when the cost group is not in the chart', function (): void {
        config(['cargo.accounting.cost_of_services_group' => 'Direct costs']);

        ($this->bill)(10_000_00);
        ($this->spendOnFuel)(4_000_00);

        $statement = ($this->income)();

        expect($statement['cost_of_services_cents'])->toBeNull()
            ->and($statement['gross_profit_cents'])->toBeNull()
            ->and($statement['gross_margin_pct'])->toBeNull()
            // The net is unaffected: it never depended on the split.
            ->and($statement['net_income_cents'])->toBe(6_000_00);
    });

    it('leaves a voided entry out of the figures', function (): void {
        $entry = ($this->bill)(10_000_00)->json('data');
        ($this->bill)(3_000_00);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/journal/{$entry['id']}/void", ['reason' => 'Billed to the wrong account'])
            ->assertOk();

        expect(($this->income)()['revenue']['total_cents'])->toBe(3_000_00);
    });
});

describe('the balance sheet', function (): void {
    it('balances, and says so', function (): void {
        ($this->bill)(10_000_00);
        ($this->spendOnFuel)(4_000_00);

        $sheet = ($this->sheet)();

        expect($sheet['balanced'])->toBeTrue()
            ->and($sheet['difference_cents'])->toBe(0)
            ->and($sheet['assets']['total_cents'])->toBe(10_000_00)
            ->and($sheet['liabilities']['total_cents'])->toBe(4_000_00)
            ->and($sheet['liabilities_and_equity_cents'])->toBe(10_000_00);
    });

    it('carries the result to date as a named equity line', function (): void {
        ($this->bill)(10_000_00);
        ($this->spendOnFuel)(4_000_00);

        $equity = ($this->sheet)()['equity'];

        // Nothing has been posted to equity, and yet the sheet balances —
        // because the ₱6,000 earned is named rather than hidden.
        expect($equity['posted_total_cents'])->toBe(0)
            ->and($equity['earnings_not_closed_cents'])->toBe(6_000_00)
            ->and($equity['total_cents'])->toBe(6_000_00);
    });

    it('agrees with the income statement for the same date', function (): void {
        ($this->bill)(10_000_00);
        ($this->spendOnFuel)(4_000_00);

        $asOf = now()->toDateString();

        expect(($this->sheet)(['as_of' => $asOf])['equity']['earnings_not_closed_cents'])
            ->toBe(($this->income)(['to' => $asOf])['net_income_cents']);
    });

    it('sums up to a date rather than over a period', function (): void {
        ($this->bill)(10_000_00, ['entry_date' => now()->subMonths(2)->toDateString()]);
        ($this->bill)(3_000_00);

        // Last month: the older haul has carried over, the newer one has not
        // happened yet.
        $sheet = ($this->sheet)(['as_of' => now()->subMonth()->endOfMonth()->toDateString()]);

        expect($sheet['assets']['total_cents'])->toBe(10_000_00)
            ->and($sheet['balanced'])->toBeTrue();

        // And today, both.
        expect(($this->sheet)()['assets']['total_cents'])->toBe(13_000_00);
    });

    it('agrees with the trial balance beside it', function (): void {
        ($this->bill)(10_000_00);
        ($this->spendOnFuel)(4_000_00);

        $trial = collect(
            $this->actingAs($this->accountant)
                ->getJson('/api/v1/accounting/ledger/trial-balance')
                ->assertOk()->json('data.rows')
        )->keyBy('code');

        $assets = collect(($this->sheet)()['assets']['groups'])
            ->flatMap(fn (array $group): array => $group['accounts'])
            ->keyBy('code');

        // The same primitive under both, which is the whole reason the
        // statements were not given their own arithmetic.
        expect($assets['1100']['balance_cents'])->toBe($trial['1100']['debit_cents']);
    });

    it('still balances when today is the default', function (): void {
        ($this->bill)(10_000_00);

        expect(($this->sheet)()['as_of'])->toBe(now()->toDateString())
            ->and(($this->sheet)()['balanced'])->toBeTrue();
    });
});

describe('who may read them', function (): void {
    it('keeps a driver out of both', function (): void {
        $this->actingAs($this->driverUser)
            ->getJson('/api/v1/accounting/statements/income')->assertForbidden();
        $this->actingAs($this->driverUser)
            ->getJson('/api/v1/accounting/statements/balance-sheet')->assertForbidden();
    });

    it('treats an unreadable date as no range rather than an error', function (): void {
        ($this->bill)(10_000_00);

        expect(($this->income)(['from' => '2026-13-45'])['revenue']['total_cents'])->toBe(10_000_00);
    });
});

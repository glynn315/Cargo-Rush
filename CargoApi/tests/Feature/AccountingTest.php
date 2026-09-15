<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The books: a chart of accounts, a general journal, a general ledger.
 *
 * Three things these tests are really about, and all three are the difference
 * between a ledger and a spreadsheet:
 *
 *   **An entry balances or it does not exist.** Not a warning, not a flag to
 *   fix later — a refusal, in the validator and again in the service, because
 *   an entry that cannot say where the money came from and where it went is
 *   missing half of what happened.
 *
 *   **A posted entry never changes.** It can be voided, which is kept and
 *   explained and stops counting. It cannot be edited, deleted or re-posted.
 *
 *   **The ledger is the journal, sorted differently.** Not a second set of
 *   rows: every balance here is a sum over the postings, so there is nothing
 *   that can drift and the trial balance agrees by construction.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->accountant = User::where('email', 'accounts@cargorush.ph')->firstOrFail();
    $this->driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();

    $this->cash = Account::query()->firstWhere('code', '1010');
    $this->receivable = Account::query()->firstWhere('code', '1100');
    $this->revenue = Account::query()->firstWhere('code', '4010');
    $this->fuel = Account::query()->firstWhere('code', '5010');
    $this->payable = Account::query()->firstWhere('code', '2010');

    /** A fuel purchase on credit: an expense, and a payable for it. */
    $this->fuelOnCredit = fn (array $overrides = []) => [
        'entry_date' => now()->toDateString(),
        'category' => JournalCategory::Fuel->value,
        'memo' => 'Diesel, 400 L at the Iponan pump',
        'lines' => [
            ['account_id' => $this->fuel->id, 'side' => 'debit', 'amount_cents' => 2400000],
            ['account_id' => $this->payable->id, 'side' => 'credit', 'amount_cents' => 2400000],
        ],
        ...$overrides,
    ];

    $this->post = fn (array $payload) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/accounting/journal', $payload);
});

describe('the chart of accounts', function (): void {
    it('is laid down for a company that has one', function (): void {
        $codes = $this->actingAs($this->accountant)->getJson('/api/v1/accounting/accounts')
            ->assertOk()
            ->json('data.*.code');

        // The five daily-sheet columns each have an account, which is what will
        // let a day's sheet post itself later without anybody deciding per row.
        expect($codes)->toContain('1010', '1100', '4010', '5010', '5020', '5030', '5040', '5050');
    });

    it('reads in the order an accountant reads a chart', function (): void {
        $types = $this->actingAs($this->accountant)->getJson('/api/v1/accounting/accounts')
            ->json('data.*.type');

        // Assets, liabilities, equity, income, expenses — not the alphabet,
        // which would put equity above expenses.
        expect(array_values(array_unique($types)))->toBe([
            AccountType::Asset->value,
            AccountType::Liability->value,
            AccountType::Equity->value,
            AccountType::Income->value,
            AccountType::Expense->value,
        ]);
    });

    it('says which side increases each account', function (): void {
        $rows = collect($this->actingAs($this->accountant)->getJson('/api/v1/accounting/accounts')->json('data'))
            ->keyBy('code');

        // The rule the whole ledger turns on, sent so no client keeps its own
        // copy of it.
        expect($rows['1010']['normal_balance'])->toBe('debit')
            ->and($rows['2010']['normal_balance'])->toBe('credit')
            ->and($rows['4010']['normal_balance'])->toBe('credit')
            ->and($rows['5010']['normal_balance'])->toBe('debit');
    });

    it('lets the office add an account of its own', function (): void {
        $this->actingAs($this->accountant)->postJson('/api/v1/accounting/accounts', [
            'code' => '5065',
            'name' => 'Batteries',
            'type' => AccountType::Expense->value,
            'group' => 'Cost of services',
        ])->assertCreated()->assertJsonPath('data.is_system', false);
    });

    it('refuses a number already in the chart', function (): void {
        $this->actingAs($this->accountant)->postJson('/api/v1/accounting/accounts', [
            'code' => '1010',
            'name' => 'Petty cash',
            'type' => AccountType::Asset->value,
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    });

    it('will not change the kind of an account that has postings', function (): void {
        ($this->post)(($this->fuelOnCredit)(['status' => JournalEntry::POSTED]))->assertCreated();

        // Flipping 5010 from expense to income would reverse the sign of every
        // figure ever recorded against it, and no report would show that it had
        // happened.
        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/accounting/accounts/{$this->fuel->id}", [
                'code' => $this->fuel->code,
                'name' => $this->fuel->name,
                'type' => AccountType::Income->value,
            ])
            ->assertStatus(422);

        expect($this->fuel->refresh()->type)->toBe(AccountType::Expense);
    });

    it('retires an account with history rather than deleting it', function (): void {
        ($this->post)(($this->fuelOnCredit)(['status' => JournalEntry::POSTED]))->assertCreated();

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/accounting/accounts/{$this->fuel->id}")
            ->assertOk()
            ->assertJsonPath('meta.retired', true);

        // The postings are the history and the account is what they name.
        expect($this->fuel->refresh()->status)->toBe(StatusValue::Inactive)
            ->and(Account::query()->firstWhere('code', '5010'))->not->toBeNull();
    });

    it('keeps a seeded account even when nothing is posted to it', function (): void {
        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/accounting/accounts/{$this->cash->id}")
            ->assertOk()
            ->assertJsonPath('meta.retired', true);

        expect(Account::query()->firstWhere('code', '1010'))->not->toBeNull();
    });

    it('deletes one the office added by mistake', function (): void {
        $id = $this->actingAs($this->accountant)->postJson('/api/v1/accounting/accounts', [
            'code' => '5999',
            'name' => 'Typo',
            'type' => AccountType::Expense->value,
        ])->json('data.id');

        $this->actingAs($this->accountant)->deleteJson("/api/v1/accounting/accounts/{$id}")
            ->assertNoContent();

        expect(Account::query()->firstWhere('code', '5999'))->toBeNull();
    });
});

describe('writing to the general journal', function (): void {
    it('takes an entry with its two sides', function (): void {
        $response = ($this->post)(($this->fuelOnCredit)())->assertCreated();

        expect($response->json('data.reference'))->toStartWith('JV-')
            ->and($response->json('data.status'))->toBe(JournalEntry::DRAFT)
            ->and($response->json('data.debit_cents'))->toBe(2400000)
            ->and($response->json('data.credit_cents'))->toBe(2400000)
            ->and($response->json('data.balanced'))->toBeTrue()
            // The accounting category — what kind of transaction this is,
            // which is the question somebody scrolling a month of entries asks.
            ->and($response->json('data.category'))->toBe(JournalCategory::Fuel->value)
            ->and($response->json('data.category_label'))->toBe('Fuel')
            ->and($response->json('data.lines'))->toHaveCount(2);
    });

    it('numbers the lines in the order they were written', function (): void {
        $lines = ($this->post)(($this->fuelOnCredit)())->json('data.lines');

        expect($lines[0]['line_no'])->toBe(1)
            ->and($lines[0]['side'])->toBe('debit')
            ->and($lines[1]['line_no'])->toBe(2)
            ->and($lines[1]['side'])->toBe('credit');
    });

    it('refuses an entry whose sides do not agree', function (): void {
        ($this->post)(($this->fuelOnCredit)([
            'lines' => [
                ['account_id' => $this->fuel->id, 'side' => 'debit', 'amount_cents' => 2400000],
                ['account_id' => $this->payable->id, 'side' => 'credit', 'amount_cents' => 2000000],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines')
            // The number that finds a transposed figure is the difference, not
            // the fact of it.
            ->assertJsonPath(
                'errors.lines.0',
                fn (string $message): bool => str_contains($message, '4,000.00'),
            );

        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('refuses one side on its own', function (): void {
        ($this->post)(($this->fuelOnCredit)([
            'lines' => [
                ['account_id' => $this->fuel->id, 'side' => 'debit', 'amount_cents' => 2400000],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('lines');
    });

    it('refuses a posting to another company chart', function (): void {
        $rival = $this->makeCompany('Bay Coast Logistics');
        $theirs = $this->asCompany($rival, fn () => Account::create([
            'code' => '1010',
            'name' => 'Their cash',
            'type' => AccountType::Asset->value,
        ]));

        // `exists:accounts,id` would have confirmed the id and posted into
        // their books. The rule resolves it through the model, so the tenant
        // scope applies and the answer is "not in your chart".
        ($this->post)(($this->fuelOnCredit)([
            'lines' => [
                ['account_id' => $this->fuel->id, 'side' => 'debit', 'amount_cents' => 1000],
                ['account_id' => $theirs->id, 'side' => 'credit', 'amount_cents' => 1000],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('lines.1.account_id');
    });

    it('refuses a posting to a retired account', function (): void {
        $this->fuel->update(['status' => StatusValue::Inactive->value]);

        ($this->post)(($this->fuelOnCredit)())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.account_id');
    });

    it('needs a category and a memo', function (): void {
        ($this->post)(($this->fuelOnCredit)(['category' => null, 'memo' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category', 'memo']);
    });

    it('gives each entry the next reference in the series', function (): void {
        $first = ($this->post)(($this->fuelOnCredit)())->json('data.reference');
        $second = ($this->post)(($this->fuelOnCredit)())->json('data.reference');

        expect([$first, $second])->toBe(['JV-0001', 'JV-0002']);
    });
});

describe('posting and voiding', function (): void {
    it('puts a draft in the books, stamped with who did it', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)())->json('data.id');

        $response = $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/journal/{$id}/post")
            ->assertOk();

        expect($response->json('data.status'))->toBe(JournalEntry::POSTED)
            ->and($response->json('data.posted_at'))->not->toBeNull()
            ->and($response->json('data.posted_by_name'))->toBe($this->accountant->name)
            ->and($response->json('data.can_edit'))->toBeFalse()
            ->and($response->json('data.can_void'))->toBeTrue();
    });

    it('posts straight away when the payload says so', function (): void {
        $response = ($this->post)(($this->fuelOnCredit)(['status' => JournalEntry::POSTED]))->assertCreated();

        // The same row either way — one definition of what posted looks like.
        expect($response->json('data.status'))->toBe(JournalEntry::POSTED)
            ->and($response->json('data.posted_by_name'))->toBe($this->accountant->name);
    });

    it('will not edit a posted entry', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)(['status' => JournalEntry::POSTED]))->json('data.id');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/accounting/journal/{$id}", ['memo' => 'Actually it was 300 L'])
            ->assertStatus(422);

        expect(JournalEntry::query()->find($id)->memo)->toBe('Diesel, 400 L at the Iponan pump');
    });

    it('will not delete a posted entry', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)(['status' => JournalEntry::POSTED]))->json('data.id');

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/accounting/journal/{$id}")
            ->assertStatus(422);

        expect(JournalEntry::query()->find($id))->not->toBeNull();
    });

    it('deletes a draft, and its sides with it', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)())->json('data.id');

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/accounting/journal/{$id}")
            ->assertNoContent();

        expect(JournalEntry::query()->find($id))->toBeNull()
            ->and(DB::table('journal_lines')->where('journal_entry_id', $id)->count())->toBe(0);
    });

    it('edits a draft by replacing its sides', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)())->json('data.id');

        $response = $this->actingAs($this->accountant)->patchJson("/api/v1/accounting/journal/{$id}", [
            'memo' => 'Diesel, 300 L at the Iponan pump',
            'lines' => [
                ['account_id' => $this->fuel->id, 'side' => 'debit', 'amount_cents' => 1800000],
                ['account_id' => $this->cash->id, 'side' => 'credit', 'amount_cents' => 1800000],
            ],
        ])->assertOk();

        // Replaced wholesale rather than merged: the payable line is gone, not
        // left behind beside its replacement.
        expect($response->json('data.lines'))->toHaveCount(2)
            ->and($response->json('data.debit_cents'))->toBe(1800000)
            ->and(collect($response->json('data.lines'))->pluck('account_code')->all())
            ->toBe(['5010', '1010']);
    });

    it('keeps a voided entry, with the reason on it', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)(['status' => JournalEntry::POSTED]))->json('data.id');

        $response = $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/journal/{$id}/void", ['reason' => 'Posted against the wrong pump'])
            ->assertOk();

        expect($response->json('data.status'))->toBe(JournalEntry::VOID)
            ->and($response->json('data.void_reason'))->toBe('Posted against the wrong pump')
            ->and($response->json('data.voided_at'))->not->toBeNull()
            // The row and its lines stay exactly where they were.
            ->and($response->json('data.lines'))->toHaveCount(2)
            ->and($response->json('data.can_void'))->toBeFalse();
    });

    it('will not void without a reason', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)(['status' => JournalEntry::POSTED]))->json('data.id');

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/journal/{$id}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    });

    it('will not void a draft', function (): void {
        $id = ($this->post)(($this->fuelOnCredit)())->json('data.id');

        // Nothing to withdraw: it was never in the books. Delete it instead.
        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/journal/{$id}/void", ['reason' => 'Changed my mind'])
            ->assertStatus(422);
    });
});

describe('the general ledger', function (): void {
    beforeEach(function (): void {
        // A month of a small haulier's books, posted: a haul invoiced, the
        // money collected, and the diesel that made it possible.
        $this->file = function (array $lines, string $memo, JournalCategory $category, string $date): void {
            ($this->post)([
                'entry_date' => $date,
                'category' => $category->value,
                'memo' => $memo,
                'status' => JournalEntry::POSTED,
                'lines' => $lines,
            ])->assertCreated();
        };

        ($this->file)([
            ['account_id' => $this->receivable->id, 'side' => 'debit', 'amount_cents' => 5000000],
            ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount_cents' => 5000000],
        ], 'CR-24817 Manila to Batangas', JournalCategory::Billing, now()->subDays(10)->toDateString());

        ($this->file)([
            ['account_id' => $this->cash->id, 'side' => 'debit', 'amount_cents' => 5000000],
            ['account_id' => $this->receivable->id, 'side' => 'credit', 'amount_cents' => 5000000],
        ], 'Southline settles CR-24817', JournalCategory::Collection, now()->subDays(3)->toDateString());

        ($this->file)([
            ['account_id' => $this->fuel->id, 'side' => 'debit', 'amount_cents' => 2400000],
            ['account_id' => $this->cash->id, 'side' => 'credit', 'amount_cents' => 2400000],
        ], 'Diesel for the Batangas run', JournalCategory::Fuel, now()->subDays(2)->toDateString());
    });

    it('shows one account with a running balance', function (): void {
        $ledger = $this->actingAs($this->accountant)
            ->getJson("/api/v1/accounting/ledger/accounts/{$this->cash->id}")
            ->assertOk()
            ->json('data');

        // ₱50,000 in, ₱24,000 out. Cash is debit-normal, so the balance runs up
        // and then down.
        expect($ledger['debit_cents'])->toBe(5000000)
            ->and($ledger['credit_cents'])->toBe(2400000)
            ->and($ledger['closing_balance_cents'])->toBe(2600000)
            ->and(array_column($ledger['lines'], 'balance_cents'))->toBe([5000000, 2600000]);
    });

    it('reads a credit-normal account in its own direction', function (): void {
        $ledger = $this->actingAs($this->accountant)
            ->getJson("/api/v1/accounting/ledger/accounts/{$this->revenue->id}")
            ->json('data');

        // Revenue of ₱50,000 is fifty thousand pesos of income, not negative
        // fifty thousand of something else.
        expect($ledger['closing_balance_cents'])->toBe(5000000)
            ->and($ledger['account']['normal_balance'])->toBe('credit');
    });

    it('settles a receivable back to nothing', function (): void {
        $ledger = $this->actingAs($this->accountant)
            ->getJson("/api/v1/accounting/ledger/accounts/{$this->receivable->id}")
            ->json('data');

        // Raised, then collected. A customer who has paid owes nothing, and the
        // ledger says so without anybody adjusting a balance by hand.
        expect($ledger['closing_balance_cents'])->toBe(0)
            ->and($ledger['entry_count'])->toBe(2);
    });

    it('counts no draft and no voided entry', function (): void {
        // A draft, and a posting withdrawn.
        ($this->post)(($this->fuelOnCredit)(['memo' => 'A draft nobody posted']))->assertCreated();

        $id = ($this->post)(($this->fuelOnCredit)([
            'memo' => 'Posted then withdrawn',
            'status' => JournalEntry::POSTED,
        ]))->json('data.id');

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/journal/{$id}/void", ['reason' => 'Duplicate of the pump slip'])
            ->assertOk();

        $ledger = $this->actingAs($this->accountant)
            ->getJson("/api/v1/accounting/ledger/accounts/{$this->fuel->id}")
            ->json('data');

        // Only the one real fill-up. A draft has not happened and a void has
        // been taken back.
        expect($ledger['closing_balance_cents'])->toBe(2400000)
            ->and($ledger['lines'])->toHaveCount(1);
    });

    it('opens a range with what the account already stood at', function (): void {
        $ledger = $this->actingAs($this->accountant)->getJson(sprintf(
            '/api/v1/accounting/ledger/accounts/%s?from=%s',
            $this->cash->id,
            now()->subDays(2)->toDateString(),
        ))->json('data');

        // The ₱50,000 collected a week ago is before the range, so it is the
        // opening balance rather than a line — a cash account carries over.
        expect($ledger['opening_balance_cents'])->toBe(5000000)
            ->and($ledger['lines'])->toHaveCount(1)
            ->and($ledger['closing_balance_cents'])->toBe(2600000);
    });

    it('opens an expense account at nothing, whatever the range', function (): void {
        $ledger = $this->actingAs($this->accountant)->getJson(sprintf(
            '/api/v1/accounting/ledger/accounts/%s?from=%s',
            $this->fuel->id,
            now()->toDateString(),
        ))->json('data');

        // An expense account is the story of one period and starts again. An
        // opening balance here would double-count the moment two months were
        // added up.
        expect($ledger['opening_balance_cents'])->toBe(0)
            ->and($ledger['account']['permanent'] ?? null)->toBeNull();
    });

    it('totals the chart by type, with the balances against it', function (): void {
        $summary = $this->actingAs($this->accountant)->getJson('/api/v1/accounting/ledger')
            ->assertOk()
            ->json('data');

        $byType = collect($summary['types'])->keyBy('type');

        expect($byType[AccountType::Asset->value]['balance_cents'])->toBe(2600000)
            ->and($byType[AccountType::Income->value]['balance_cents'])->toBe(5000000)
            ->and($byType[AccountType::Expense->value]['balance_cents'])->toBe(2400000);
    });

    it('balances', function (): void {
        $trial = $this->actingAs($this->accountant)->getJson('/api/v1/accounting/ledger/trial-balance')
            ->assertOk()
            ->json('data');

        // The check that the books are whole. Every entry balances, so the two
        // columns of the trial balance agree — and if they ever did not,
        // something had written to `journal_lines` without going through the
        // service.
        expect($trial['balanced'])->toBeTrue()
            ->and($trial['difference_cents'])->toBe(0)
            ->and($trial['debit_total_cents'])->toBe(5000000)
            ->and($trial['credit_total_cents'])->toBe(5000000);

        // Accounts nobody has used are left off: a page of forty zeroes hides
        // the three lines that matter.
        expect(collect($trial['rows'])->pluck('code')->all())->toBe(['1010', '4010', '5010']);
    });

    it('finds every entry that touched one account', function (): void {
        $references = $this->actingAs($this->accountant)
            ->getJson("/api/v1/accounting/journal?account_id={$this->cash->id}")
            ->assertOk()
            ->json('data.*.reference');

        // Asked from the journal side — "everything that touched 1010" — which
        // is a different question from the ledger's "show me 1010".
        expect($references)->toHaveCount(2);
    });

    it('filters the journal by accounting category', function (): void {
        $categories = $this->actingAs($this->accountant)
            ->getJson('/api/v1/accounting/journal?category='.JournalCategory::Fuel->value)
            ->assertOk()
            ->json('data.*.category');

        expect($categories)->toBe([JournalCategory::Fuel->value]);
    });
});

describe('who may open the books', function (): void {
    it('is refused to a driver outright', function (): void {
        $this->actingAs($this->driverUser)->getJson('/api/v1/accounting/journal')->assertForbidden();
        $this->actingAs($this->driverUser)->getJson('/api/v1/accounting/ledger')->assertForbidden();
    });

    it('separates reading the books from posting to them', function (): void {
        // Treasury moves money and files spend, and reads the books to do it.
        // What an entry says about it is the accountant's call — an install
        // where both could post has nobody left to check the other.
        $treasurer = User::factory()->create(['role' => 'treasury']);

        $this->actingAs($treasurer)->getJson('/api/v1/accounting/journal')->assertOk();
        $this->actingAs($treasurer)->postJson('/api/v1/accounting/journal', ($this->fuelOnCredit)())
            ->assertForbidden();
    });
});

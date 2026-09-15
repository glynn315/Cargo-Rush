<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Requests;

use App\Domain\Accounting\DTO\JournalEntryData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A journal entry, written or edited.
 *
 * Most of this is an ordinary form. What is not ordinary is the closing check:
 * **the debits and the credits must agree.** Every accounting system in the
 * world enforces it, and it is worth being clear about why it belongs in the
 * validator and not only in the service.
 *
 * An unbalanced entry is not a record with a mistake in it — it is not a
 * transaction. Money came from somewhere and went somewhere, and an entry that
 * cannot say both is missing half of what happened. If it were caught deeper
 * in, the caller would get a 500 or a bare 422 with no field to point at;
 * caught here it comes back naming the shortfall in centavos, which is the one
 * thing that lets somebody find their typo.
 *
 * ## What the payload does not carry
 *
 * `reference` is the system's — `JV-0004` comes from the series, and a caller
 * that could choose one could collide with a number already quoted to an
 * auditor.
 *
 * `void` is not a status a form can set. Withdrawing a posted entry is its own
 * act with its own reason, on its own endpoint.
 *
 * `company_id` is nowhere, as everywhere else: it is the scope, stamped from
 * the account that made the request.
 */
class JournalEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            // The day the transaction belongs to, which is not necessarily
            // today: an entry keyed on Monday for Friday's fuel is Friday's.
            // A future date is allowed — an accrual dated month-end is written
            // before month-end — and it is the office's judgement, not a rule.
            'entry_date' => [$required, 'date'],

            /**
             * The accounting category — what kind of transaction this is.
             *
             * Required, and a closed list. It is what the journal is grouped
             * and filtered by, and a nullable one would leave the office with a
             * page of uncategorised rows that no report could total. See
             * `JournalCategory` for why it is an enum rather than a table.
             */
            'category' => [$required, Rule::in(JournalCategory::values())],

            // The narration. Required because an entry nobody can read is an
            // entry somebody will have to reconstruct from its accounts.
            'memo' => [$required, 'string', 'max:255'],

            /**
             * `draft` to keep working on it, `posted` to put it in the books.
             *
             * Absent means draft on a create (the column's own default), which
             * is the safe end: an entry that posted itself because a field was
             * left out would be in the books before anybody checked it.
             */
            'status' => ['sometimes', Rule::in([JournalEntry::DRAFT, JournalEntry::POSTED])],

            /**
             * The sides. At least two, because one side is not a transaction.
             *
             * Required on a PATCH as well when it is sent at all: lines are
             * replaced wholesale rather than merged, so a partial list would
             * silently delete the rest.
             */
            'lines' => [$required, 'array', 'min:2', 'max:100'],

            /**
             * Each line names an account from *this company's* chart.
             *
             * `exists` is scoped by hand rather than left to the plain rule:
             * `exists:accounts,id` would confirm an id from any company on the
             * platform, which is both a leak and a posting into somebody else's
             * books. The closure runs the query through the model, so the
             * tenant scope applies.
             */
            'lines.*.account_id' => [
                'required', 'string', 'size:26',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->find($value);

                    if ($account === null) {
                        $fail('That account is not in your chart of accounts.');

                        return;
                    }

                    // A retired account keeps its history and takes no new
                    // postings — the same rule an expense category follows.
                    if (! $account->isActive()) {
                        $fail(sprintf('%s is retired and cannot take new postings.', $account->label()));
                    }
                },
            ],

            'lines.*.side' => ['required', Rule::in([BalanceSide::Debit->value, BalanceSide::Credit->value])],

            /**
             * Always positive, and always in centavos.
             *
             * The direction is `side`, never a minus sign. A negative amount
             * would be a second way to say the same thing, and two ways to
             * express one fact is how a ledger ends up with two answers.
             */
            'lines.*.amount_cents' => ['required', 'integer', 'min:1'],

            'lines.*.memo' => ['nullable', 'string', 'max:255'],

            // What this side was about. Optional, independent, and validated
            // only for shape — the ids belong to tenant-scoped tables, so a
            // wrong one writes nothing readable and a foreign one cannot be
            // read at all.
            'lines.*.truck_id' => ['nullable', 'string', 'size:26'],
            'lines.*.trip_id' => ['nullable', 'string', 'size:26'],
            'lines.*.customer_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'A journal entry needs its two sides.',
            'lines.min' => 'A journal entry needs at least two lines — what was debited, and what was credited.',
            'lines.*.amount_cents.min' => 'A line has to be for some amount.',
            'memo.required' => 'Say what the entry is for.',
            'category.required' => 'Choose what kind of transaction this is.',
        ];
    }

    /**
     * The closing check: do the two sides agree?
     *
     * Run after the field rules, so a payload with a missing amount is told
     * about the amount rather than about a difference the missing amount
     * caused. The message names the shortfall in pesos, because "your entry is
     * out by ₱1,500.00" is the sentence that finds a transposed figure and
     * "the entry does not balance" is not.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Nothing to say about a difference caused by a field that is
            // itself wrong — and `validated()` is not available until the run
            // finishes, so the arithmetic is done on the validator's own data.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $payload = $validator->getData();

            if (! is_array($payload['lines'] ?? null)) {
                return;
            }

            $data = JournalEntryData::fromArray($payload);

            if ($data->isBalanced()) {
                return;
            }

            $validator->errors()->add('lines', sprintf(
                'The two sides do not agree: debits are ₱%s and credits are ₱%s, a difference of ₱%s.',
                number_format($data->totalDebits() / 100, 2),
                number_format($data->totalCredits() / 100, 2),
                number_format(abs($data->difference()) / 100, 2),
            ));
        });
    }

    public function toData(): JournalEntryData
    {
        return JournalEntryData::fromArray($this->validated());
    }
}

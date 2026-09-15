<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Requests;

use App\Domain\Accounting\DTO\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * An account added to or edited in the chart.
 *
 * The chart is seeded with one a haulier can work from, and this is how an
 * office extends it — a second fuel account per depot, a loan account for a
 * new unit, whatever their accountant keeps.
 *
 * Two rules carry the weight. The code is unique *within the company*, which
 * is what lets two hauliers on one install both number their cash account 1010.
 * And the type is fixed once postings exist: changing an account from expense
 * to income after it has been posted to would silently reverse the sign of
 * every figure already in the books, which is not an edit — it is a rewrite of
 * history that no report would flag.
 */
class AccountRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $account = $this->route('account');
        $id = $account instanceof Account ? $account->getKey() : null;

        return [
            /**
             * The account number the office quotes.
             *
             * Unique per company, and the rule says so explicitly:
             * `unique:accounts,code` on its own is unique across the platform,
             * so the second haulier to open a cash account would be told 1010
             * was taken by somebody they have never heard of.
             */
            'code' => [
                $required, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('accounts', 'code')
                    ->where(fn (Builder $query) => $query->where('company_id', app(Tenant::class)->id()))
                    ->whereNull('deleted_at')
                    ->ignore($id),
            ],

            'name' => [$required, 'string', 'max:120'],

            /**
             * asset, liability, equity, income or expense.
             *
             * Immutable once anything has been posted here — see
             * `AccountService::update()`, which refuses it with a sentence
             * rather than a validation error, because whether it is allowed
             * depends on the account's history rather than on the payload.
             */
            'type' => [$required, Rule::in(AccountType::values())],

            /**
             * A sub-heading within the type: "Current asset", "Cost of
             * services". Free text, and the office's to choose — it groups the
             * rows on a statement and changes no total.
             */
            'group' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],

            /**
             * `active` or `inactive`, and nothing else from this vocabulary
             * means anything for an account. Retiring one keeps its history and
             * stops new postings; the other statuses are about trips and
             * invoices.
             */
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'That account number is already in your chart.',
            'code.regex' => 'An account number can hold letters, digits, dots, dashes and underscores.',
            'type.required' => 'Choose what kind of account this is — it decides which way its balance runs.',
        ];
    }

    public function toData(): AccountData
    {
        return AccountData::fromArray($this->validated());
    }
}

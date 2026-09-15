<?php

declare(strict_types=1);

namespace App\Domain\Customer\Requests;

use App\Domain\Customer\DTO\CustomerData;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;

class CustomerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:160'],
            'contact' => [$required, 'string', 'max:160'],

            /**
             * Where the firm's loads leave from.
             *
             * Optional, and the desk will usually leave it empty — a customer
             * they typed in is a customer they ring. It arrives filled in for a
             * firm that signed itself up in the app and pinned its store, and is
             * editable here because the record is the haulier's to keep straight.
             *
             * A pair or nothing, the same rule both ends of a trip follow.
             */
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'status' => ['sometimes', Rule::in(StatusValue::values())],

            /**
             * How this firm is taxed.
             *
             * Both are properties of who is being billed rather than of what
             * was hauled, which is why they live on the customer and not on
             * the trip or the invoice form — and why every invoice raised for
             * them, by hand or by a delivery, picks them up automatically.
             *
             * `tin` is printed on the document. A VAT invoice without the
             * buyer's TIN is one their accounts payable will send back.
             */
            'tin' => ['nullable', 'string', 'max:20'],
            'vat_treatment' => ['sometimes', Rule::in(VatTreatment::values())],
            'withholds_tax' => ['sometimes', 'boolean'],
            /**
             * Null means the statutory rate, so a change in law reaches every
             * customer who never had a special one. Bounded well above 2% —
             * some payments to contractors are withheld at higher rates, and a
             * cap at the common case would be wrong the first time somebody
             * needed 5%.
             */
            'withholding_rate_bp' => ['nullable', 'integer', 'min:0', 'max:5000'],

            /**
             * What the firm signs in with. Optional, because a customer the
             * office only ever books for on the phone does not need an
             * account — but given one, `CustomerService` creates the login
             * along with the record, so the firm can book its own work
             * straight away.
             *
             * Unique against `users`, since that is where it lands: an address
             * already in use is somebody else's account, and quietly attaching
             * a second firm to it would let one customer read another's
             * deliveries.
             */
            'email' => ['nullable', 'email', 'max:160', $this->unusedAddress()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'That address already has an account. Use another, or leave it blank.',
        ];
    }

    public function toData(): CustomerData
    {
        return CustomerData::fromArray($this->validated());
    }

    /**
     * Unique among accounts, except the ones this customer already holds.
     *
     * Written the long way round rather than as `whereNot('customer_id', ...)`
     * because staff accounts have no customer at all, and a plain `<>` in SQL
     * drops those rows — which would let a customer login be created on an
     * address the office already signs in with.
     */
    private function unusedAddress(): object
    {
        $customer = $this->route('customer');

        $rule = Rule::unique('users', 'email');

        if ($customer instanceof Customer) {
            // Nested, because the closure's conditions are ANDed onto the address
            // check at the top level — and `A and B or C` in SQL is not the
            // question being asked.
            $rule->where(fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->whereNull('customer_id')
                ->orWhere('customer_id', '!=', $customer->id)));
        }

        return $rule;
    }
}

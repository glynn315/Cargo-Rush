<?php

declare(strict_types=1);

namespace App\Domain\Billing\Requests;

use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Recording money that has moved.
 *
 * The allocations are the interesting half. One payment may be applied across
 * several invoices — which is how customers actually settle, a month at a time
 * rather than a document at a time — so this takes a list rather than a single
 * invoice id.
 *
 * The list may also be **empty**, and that is a real case rather than an
 * oversight: a customer sends money on account before the invoice is raised,
 * and refusing to record it until there is something to apply it to means the
 * bank statement and the system disagree for a fortnight. It sits unallocated
 * until somebody puts it somewhere.
 */
class PaymentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'direction' => ['sometimes', Rule::in(InvoiceDirection::values())],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            // Not in the future: money that has not arrived is not a payment,
            // and a forward-dated one would drop into a period that has not
            // been reported on yet.
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['sometimes', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],

            'allocations' => ['sometimes', 'array'],
            'allocations.*.invoice_id' => ['required', 'string', 'exists:invoices,id'],
            'allocations.*.amount_cents' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * The parts may not add up to more than the whole.
     *
     * Checked here rather than only in the service so the message names the
     * payment rather than the last invoice that happened to tip it over — the
     * error is with the split, not with any one line of it.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allocations = (array) $this->input('allocations', []);

                if ($allocations === []) {
                    return;
                }

                $allocated = array_sum(array_column($allocations, 'amount_cents'));

                if ($allocated > (int) $this->input('amount_cents')) {
                    $validator->errors()->add(
                        'allocations',
                        'Those add up to more than the payment itself.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<int, array{invoice_id: string, amount_cents: int}>
     */
    public function allocations(): array
    {
        return array_map(
            static fn (array $row): array => [
                'invoice_id' => (string) $row['invoice_id'],
                'amount_cents' => (int) $row['amount_cents'],
            ],
            (array) ($this->validated()['allocations'] ?? []),
        );
    }

    /**
     * The payment's own columns, without the allocations.
     *
     * Deliberately not called `attributes()`: that is already a `FormRequest`
     * method — the one that supplies human names for validation messages — and
     * overriding it with a different meaning would break every message on this
     * request in a way that only shows up when validation fails.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return collect($this->validated())->except('allocations')->all();
    }
}

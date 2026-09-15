<?php

declare(strict_types=1);

namespace App\Domain\Customer\Resources;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Customer
 */
class CustomerResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $login = $this->logins->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact' => $this->contact,

            /**
             * Where this firm's loads leave from, when they said so.
             *
             * Null for every customer the desk typed in — there is no field for
             * it on their form, and there does not need to be: the office rings
             * them about a pickup. Filled in for a firm that signed itself up in
             * the app and pinned its store, which is worth showing here because
             * it is the only thing the desk knows about a customer who arrived
             * without a phone call.
             */
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // Both come from the repository as subquery columns; the fallback
            // covers a single record fetched without them.
            'trips_total' => (int) ($this->trips_count ?? $this->trips()->count()),
            'outstanding_cents' => (int) ($this->outstanding_cents ?? $this->outstandingCents()),
            'currency' => 'PHP',
            'rating' => $this->rating,
            'status' => $this->status->value,

            /**
             * How this firm is taxed, which every invoice raised for them
             * reads. Sent so the customer form can show it and the billing
             * screen can explain why a document carries the figures it does.
             */
            'tin' => $this->tin,
            'vat_treatment' => $this->vatTreatment()->value,
            'vat_label' => $this->vatTreatment()->label(),
            'withholds_tax' => $this->withholdsTax(),
            // Null means the statutory rate applies.
            'withholding_rate_bp' => $this->withholding_rate_bp,

            // What the firm signs in with, or null for one that cannot: the
            // list shows this because "can this customer file their own
            // requests?" is otherwise invisible from the office.
            'login_email' => $login?->email,

            /**
             * The starting password, on the one response that just created the
             * account — see `Customer::$newLogin`. Null on every read, because
             * a password is not a field of a customer record; this is the
             * office being told once what to pass on, in the reply to the form
             * they filled in.
             */
            'default_password' => $this->newLogin['password'] ?? null,

            ...$this->stamps(),
        ];
    }
}

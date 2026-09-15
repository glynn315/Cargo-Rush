<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;

final class CustomerData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $contact = null,
        /**
         * Where the firm's loads leave from.
         *
         * Arrives filled in when a shipper signed themselves up and pinned
         * their store; editable here because the record is the haulier's to
         * keep straight, and a customer who has moved warehouse will tell the
         * desk long before they think to change it in the app.
         */
        public readonly ?string $address = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $rating = null,
        public readonly ?StatusValue $status = null,
        /**
         * The address the firm signs in with.
         *
         * Deliberately absent from `toArray()`: there is no `email` column on
         * `customers`, because the login is a `users` row — the same split the
         * driver has, where the account and the business record are two
         * things. `CustomerService` reads this to make that account, and left
         * empty it falls back to the contact when the contact is an address.
         */
        public readonly ?string $email = null,

        /**
         * How this firm is taxed.
         *
         * Real columns, unlike `email` above — they belong to the customer
         * record, and every invoice raised for the firm reads them.
         */
        public readonly ?string $tin = null,
        public readonly ?VatTreatment $vat_treatment = null,
        public readonly ?bool $withholds_tax = null,
        public readonly ?int $withholding_rate_bp = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: $attributes['name'] ?? null,
            contact: $attributes['contact'] ?? null,
            address: $attributes['address'] ?? null,
            latitude: isset($attributes['latitude']) ? (float) $attributes['latitude'] : null,
            longitude: isset($attributes['longitude']) ? (float) $attributes['longitude'] : null,
            rating: isset($attributes['rating']) ? (float) $attributes['rating'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            email: $attributes['email'] ?? null,
            tin: $attributes['tin'] ?? null,
            vat_treatment: isset($attributes['vat_treatment'])
                ? VatTreatment::from($attributes['vat_treatment'])
                : null,
            // Cast rather than passed through: multipart and query strings
            // deliver "0" and "false" as strings, and both are truthy.
            withholds_tax: isset($attributes['withholds_tax'])
                ? filter_var($attributes['withholds_tax'], FILTER_VALIDATE_BOOL)
                : null,
            withholding_rate_bp: isset($attributes['withholding_rate_bp'])
                ? (int) $attributes['withholding_rate_bp']
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact' => $this->contact,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'rating' => $this->rating,
            'status' => $this->status?->value,
            'tin' => $this->tin,
            'vat_treatment' => $this->vat_treatment?->value,
            'withholds_tax' => $this->withholds_tax,
            'withholding_rate_bp' => $this->withholding_rate_bp,
        ];
    }
}

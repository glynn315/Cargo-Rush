<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * A company signing up, and the first person in it.
 *
 * Both halves in one object because they are one act. A company with no account
 * is a row nobody can reach, and an account with no company has nowhere to put
 * anything — creating either without the other leaves the system in a state it
 * has no screen for.
 *
 * `device_name` carries the same meaning it does on a login: present asks for a
 * bearer token, absent sets the SPA cookie. Registration signs the new owner in
 * as it finishes, so it needs to know which.
 */
final class RegistrationData extends Data
{
    public function __construct(
        // The company.
        public readonly string $company_name,
        public readonly ?string $contact_phone = null,
        public readonly ?string $address = null,
        /**
         * The pin on the yard, store or depot.
         *
         * What puts the company on the carrier list a customer picks from —
         * `address` cannot be sorted by distance or drawn on a map, and a
         * shipper choosing who to ask needs both. Optional, because whoever
         * registers may be nowhere near the place; `PATCH company` is where it
         * arrives if it does not arrive here.
         */
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        // The person registering it, who becomes its administrator.
        public readonly string $name = '',
        public readonly string $email = '',
        public readonly string $password = '',
        public readonly ?string $device_name = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            company_name: (string) ($attributes['company_name'] ?? ''),
            contact_phone: $attributes['contact_phone'] ?? null,
            address: $attributes['address'] ?? null,
            // Cast, because a JSON document and a form post can both carry a
            // coordinate as a string and the column is a decimal.
            latitude: isset($attributes['latitude']) ? (float) $attributes['latitude'] : null,
            longitude: isset($attributes['longitude']) ? (float) $attributes['longitude'] : null,
            name: (string) ($attributes['name'] ?? ''),
            email: (string) ($attributes['email'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            device_name: $attributes['device_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'company_name' => $this->company_name,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'device_name' => $this->device_name,
        ];
    }

    /**
     * The `companies` columns, ready to write.
     *
     * The registering person doubles as the company's contact — they are the
     * only human the system knows about at this point, and an account with
     * nobody to contact about it is one nobody can be warned about. The office
     * can change it later; it is a separate field on the company, not a mirror
     * of whoever holds an account today.
     */
    public function companyAttributes(): array
    {
        return [
            'name' => $this->company_name,
            'contact_name' => $this->name,
            'contact_email' => $this->email,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,
            // A pair or neither, which the form request has already enforced.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    /** True when the caller wants a token rather than a session cookie. */
    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }
}

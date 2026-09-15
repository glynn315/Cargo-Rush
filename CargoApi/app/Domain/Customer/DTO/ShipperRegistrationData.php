<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * A shipper signing themselves up.
 *
 * The counterpart of `RegistrationData`, which is a *company* arriving at the
 * platform. This is a customer arriving: somebody with a pallet and nobody to
 * carry it.
 *
 * One act and one row — the login. No `customers` row is written here, because
 * a `customers` row is one haulier's account for a shipper and no haulier has
 * been chosen yet. That choice belongs to the first request, along with where
 * the load is going out from, and `ShipperAccounts::open()` is what turns it
 * into a customer on that carrier's books.
 *
 * It registers a **customer**, not a business. One name does for both the login
 * and, later, the customer record: whoever signs up is who the books are opened
 * in the name of and who the desk rings about a pickup, and asking for a trading
 * name as well would be a second field to type and a second thing for the two
 * records to disagree about. A firm that wants to be billed under a trading name
 * is a firm the office edits, which it can already do.
 *
 * The phone number is the only other thing asked for, and it is carried on the
 * login (`users.phone`) until there is a carrier to copy it to. It is what the
 * desk rings; a customer the desk cannot reach is a request they cannot confirm.
 */
final class ShipperRegistrationData extends Data
{
    public function __construct(
        /**
         * The customer. One name, used for both.
         *
         * The app registers a **customer**, not a business: whoever signs up is
         * who a haulier's books are opened in the name of, and who the desk
         * rings about a pickup. There is no separate trading name to ask for —
         * a second name field would be one more thing to type and one more
         * thing for the two records to disagree about.
         */
        public readonly string $name = '',
        public readonly ?string $contact_phone = null,
        public readonly string $email = '',
        public readonly string $password = '',
        public readonly ?string $device_name = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: (string) ($attributes['name'] ?? ''),
            contact_phone: $attributes['contact_phone'] ?? null,
            email: (string) ($attributes['email'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            device_name: $attributes['device_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact_phone' => $this->contact_phone,
            'email' => $this->email,
            'password' => $this->password,
            'device_name' => $this->device_name,
        ];
    }

    /**
     * The `users` columns for the login this creates.
     *
     * `company_id` is absent and stays null: there is no haulier yet.
     * `chooses_carrier` is what says why it is null — this account arrived at
     * the platform rather than at a company, and may pick a different carrier
     * for every load. See the migration that added the column.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            // Kept on the login because there is nowhere else for it yet.
            // `ShipperAccounts::open()` copies it onto the first `customers`
            // row it writes.
            'phone' => $this->contact_phone,
            // Hashed by the model's cast, as every other write of a password in
            // this codebase is.
            'password' => $this->password,
        ];
    }

    /** True when the caller wants a bearer token rather than a session cookie. */
    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }
}

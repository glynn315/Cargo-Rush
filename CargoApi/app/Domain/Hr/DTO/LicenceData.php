<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * The licence details HR takes when the person being registered drives.
 *
 * Its own DTO rather than two more fields on `EmployeeData`, because they are
 * not employee columns — they belong to the `drivers` row, and `EmployeeData`
 * is handed straight to `Employee::create()`. Smuggling them through would mean
 * either two columns the table does not have, or a DTO whose `persistable()`
 * has to be filtered by whoever uses it, which defeats the point of having one.
 *
 * Null where the job does not drive. That is the ordinary case — most of the
 * office — and it is why every method that takes one of these accepts null
 * rather than an empty instance: "there is no licence" and "the licence is
 * blank" are different things, and only the first is true of a mechanic.
 */
final class LicenceData extends Data
{
    public function __construct(
        public readonly ?string $licence_no = null,
        public readonly ?string $licence_expiry = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            licence_no: $attributes['licence_no'] ?? null,
            licence_expiry: $attributes['licence_expiry'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
        ];
    }

    /** Is there actually a licence number here to file somebody under? */
    public function hasLicence(): bool
    {
        return $this->licence_no !== null && $this->licence_no !== '';
    }
}

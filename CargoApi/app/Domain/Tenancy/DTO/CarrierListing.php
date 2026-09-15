<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\DTO;

use App\Domain\Tenancy\Models\Company;

/**
 * One haulier as a shipper sees it on the carrier list.
 *
 * The company, plus the three things that are true only in relation to whoever
 * is asking: how far away it is from the load, how much it could take today,
 * and whether this customer already has an account with it.
 *
 * A small object rather than four loose attributes hung on the model, because
 * none of them is a column — `distance_km` depends on where the customer is
 * standing, and writing it onto the `Company` would make a value that changes
 * per request look like part of the record.
 */
final class CarrierListing
{
    public function __construct(
        public readonly Company $company,
        /** Kilometres from the point the customer asked about; null if they gave none. */
        public readonly ?float $distance_km,
        /** Units not in the workshop and not retired. What could be sent out. */
        public readonly int $vehicles_ready,
        /** What those units can carry between them, in kilograms. */
        public readonly int $capacity_kg,
        /** Has this shipper transacted with this haulier before? */
        public readonly bool $linked,
    ) {}

    /**
     * Where an unpinned or far-off carrier sorts.
     *
     * Nulls last, and by a real number rather than by a nullable one, because
     * the only carriers with no distance are the ones a customer is already
     * working with — kept on the list deliberately, and belonging under the
     * ones they could reach today.
     */
    public function sortKey(): float
    {
        return $this->distance_km ?? PHP_FLOAT_MAX;
    }
}

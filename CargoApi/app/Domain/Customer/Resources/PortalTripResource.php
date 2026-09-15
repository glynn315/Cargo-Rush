<?php

declare(strict_types=1);

namespace App\Domain\Customer\Resources;

use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Resources\TripResource;
use Illuminate\Http\Request;

/**
 * A delivery as the customer reads it — the office's trip, plus whose it is.
 *
 * Everything on `TripResource` and two more fields. The office never needs
 * them: a dispatcher is looking at one company's board and the company is the
 * one they signed in to. A customer may be using three hauliers at once, and a
 * list of deliveries that does not say who is carrying which is a list they
 * cannot ring anybody about.
 *
 * A subclass rather than two fields added to the shared resource, so the extra
 * query and the extra bytes land only on the screens that need them.
 *
 * @mixin Trip
 */
class PortalTripResource extends TripResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            // Eager-loaded by `PortalService` inside the carrier's own tenancy.
            // Read here without it, the relation would resolve under the
            // caller's company and come back null.
            'carrier_id' => $this->company_id,
            'carrier' => $this->company?->name,
        ];
    }

    /**
     * The customer sees the pre-trip check, but not a haulier's dirty laundry.
     *
     * They get the whole itemised checklist once the unit has passed — which is
     * true of anything that has left the yard, since a run cannot start without
     * one — and before that only that it has not been cleared yet. The
     * difference matters: a customer waiting on a pickup is owed the fact that
     * the truck is being seen to; which brake failed is between the fleet and
     * its mechanic. See `InspectionService::summaryFor()`.
     */
    protected function detailedInspection(): bool
    {
        return false;
    }
}

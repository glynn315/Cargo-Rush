<?php

declare(strict_types=1);

namespace App\Domain\Trip\Resources;

use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/**
 * @mixin Trip
 */
class TripResource extends ApiResource
{
    /**
     * Names, not ids, for the four related records: a trip row is read by a
     * person, and `driver_name` is what the table column actually prints. The
     * ids ride along for the edit form.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'origin' => $this->origin,
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination' => $this->destination,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,
            // Derived, so the client does not re-implement haversine.
            'mapped' => $this->isMapped(),
            'cargo' => $this->cargo,
            'weight_kg' => $this->weight_kg,
            'pieces' => $this->pieces,
            'handling' => $this->handling,

            // Quoted from the tariff when the trip was booked, so the
            // customer, the ledger and the invoice all read one figure.
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,

            'customer_id' => $this->customer_id,
            'customer' => $this->customer?->name,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'helper_id' => $this->helper_id,
            'helper_name' => $this->helper?->name,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,

            /**
             * Where the unit's pre-trip check stands.
             *
             * On every trip payload because all three clients need it and none
             * of them should be working it out: the handset decides whether
             * tapping Start opens the checklist or leaves on the run, the office
             * board can see that a unit was looked over before it rolled, and
             * the customer can see that somebody checked the truck their load is
             * on. `InspectionService::summaryFor()` is the one place that
             * decides what it says.
             *
             * Costs no query: the check is eager-loaded with the trip.
             */
            'inspection' => app(InspectionService::class)->summaryFor($this->resource, $this->detailedInspection()),

            'status' => $this->status->value,
            'pickup_place' => $this->pickup_place,
            'dropoff_place' => $this->dropoff_place,
            'scheduled_at' => $this->iso($this->scheduled_at),
            'eta' => $this->iso($this->eta),
            'distance_total_m' => $this->distance_total_m,
            // Set when the delivery put this run on the books. Null means it
            // has not earned anything yet — which for anything undelivered is
            // the right answer, not a missing one.
            'billed_at' => $this->iso($this->billed_at),

            ...$this->stamps(),
        ];
    }

    /**
     * May this reader see the itemised check, failures and all?
     *
     * Yes here: this resource answers the office and the driver, and both are
     * looking at their own fleet. `PortalTripResource` overrides it to no — a
     * customer sees the itemised result only once the unit has passed, because
     * a held truck is the haulier's own maintenance business and "their brakes
     * failed" is not a sentence to put in front of a client about a load that
     * has not moved. What the customer always sees is whether the run has been
     * cleared, which is the part that concerns them.
     */
    protected function detailedInspection(): bool
    {
        return true;
    }
}

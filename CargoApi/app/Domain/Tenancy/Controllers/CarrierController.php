<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Controllers;

use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\NearbyCarriersRequest;
use App\Domain\Tenancy\Resources\CarrierResource;
use App\Domain\Tenancy\Services\CarrierDirectory;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/carriers` — who is hauling near here, to anybody who asks.
 *
 * The third public endpoint, alongside registering and signing in, and it
 * exists for the same reason they do: a firm with a pallet and no haulier has
 * to be able to see who could carry it *before* they have an account. An
 * authenticated-only directory would mean asking somebody to register with a
 * platform before showing them whether anybody on it serves their town.
 *
 * It is not part of the sign-up form. Choosing a haulier happens per load, once
 * there is an account and a load — this is the shop window, read by whoever is
 * still deciding whether to come in.
 *
 * What it can see is `CarrierDirectory`'s answer and nothing more: a name, a
 * yard, a phone number and how many units are free. That is what a haulier
 * paints on the side of a truck. No trips, no rates, no customers, no people —
 * so there is nothing here that a company would mind a stranger reading, which
 * is the test a public endpoint has to pass.
 *
 * Metered per IP (`throttle:carriers`). It is a read across every company on
 * the platform, and the only unauthenticated one.
 *
 * A signed-in shipper uses `GET portal/carriers` instead, which is this list
 * plus which of them they already deal with — and which refuses an account the
 * office created, because that customer is one haulier's and is shown no
 * others.
 */
class CarrierController extends ApiController
{
    public function __construct(private readonly CarrierDirectory $directory) {}

    public function __invoke(NearbyCarriersRequest $request): JsonResponse
    {
        $point = $request->point();

        $listings = $this->directory->near(
            lat: $point['lat'] ?? null,
            lng: $point['lng'] ?? null,
            radiusKm: $request->radiusKm(),
            search: $request->search(),
            // Nobody is signed in, so there is nothing to have dealt with
            // before. Every card comes back `linked: false`.
            linkedCompanyIds: [],
        );

        return $this->collection(CarrierResource::collection($listings), $listings);
    }
}

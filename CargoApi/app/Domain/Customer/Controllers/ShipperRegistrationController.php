<?php

declare(strict_types=1);

namespace App\Domain\Customer\Controllers;

use App\Domain\Customer\Requests\RegisterShipperRequest;
use App\Domain\Customer\Services\ShipperRegistrationService;
use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/register/customer` — the shipper's front door.
 *
 * The other two public writes are a haulier registering and anybody signing in,
 * and this answers in exactly the shape both of those do, down to the token
 * living in `meta`: a firm that has just signed up is a firm that is now signed
 * in, and the app should carry on into the portal through the same code path it
 * uses after a sign-in rather than a second one written only for this case.
 *
 * A name, a number and a login, and no carrier. Who carries a load is asked
 * per load on the request form, from the hauliers near wherever that load is
 * going out from — so this opens an account that belongs to nobody yet, and the
 * first request is what makes them somebody's customer. `RegisterShipperRequest`
 * has the argument for why that order is the right way round.
 *
 * Which means the response names no company: `company_id` and `company_name`
 * come back null, exactly as they do for such an account on `GET /me`, and both
 * fill in the moment a carrier is picked. A client that reads them for a header
 * has to expect it.
 *
 * Throttled on the same limiter as a company registration. It writes a login on
 * an unauthenticated call, which is worth metering whether it succeeds or not.
 */
class ShipperRegistrationController extends ApiController
{
    public function __construct(private readonly ShipperRegistrationService $registration) {}

    public function __invoke(RegisterShipperRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->registration->register($request->toData(), $request);

        return $this->item(
            // The same resource `POST /login` and `GET /me` return. It names no
            // carrier yet; the app opens on the request form, which is where
            // one is chosen.
            new MeResource($user),
            $token === null ? [] : ['token' => $token, 'token_type' => 'Bearer'],
            201,
        );
    }
}

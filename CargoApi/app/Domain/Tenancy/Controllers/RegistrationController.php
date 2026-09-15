<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Controllers;

use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\RegisterCompanyRequest;
use App\Domain\Tenancy\Services\RegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/register` — the front door.
 *
 * The other public endpoint is login, and the pair is the whole of the
 * unauthenticated API. This one answers in exactly the shape login does, down
 * to the token living in `meta`: a client that has just registered is a client
 * that is now signed in, and it should be able to carry on into the app through
 * the same code path it uses after a sign-in rather than a second one written
 * only for this case.
 */
class RegistrationController extends ApiController
{
    public function __construct(private readonly RegistrationService $registration) {}

    public function __invoke(RegisterCompanyRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->registration->register($request->toData(), $request);

        return $this->item(
            // The same resource `POST /login` and `GET /me` return. The company
            // it now carries is what the client redirects into.
            new MeResource($user),
            $token === null ? [] : ['token' => $token, 'token_type' => 'Bearer'],
            201,
        );
    }
}

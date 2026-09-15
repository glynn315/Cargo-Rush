<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Requests\ChangePasswordRequest;
use App\Domain\Identity\Requests\ForgotPasswordRequest;
use App\Domain\Identity\Requests\ResetPasswordRequest;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\PasswordService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * Forgetting a password, and changing one.
 *
 * The first two are public — they have to be, since somebody who cannot sign
 * in cannot authenticate to ask — which makes them the third and fourth
 * unauthenticated routes in the system, and both are throttled.
 */
class PasswordController extends ApiController
{
    public function __construct(
        private readonly PasswordService $passwords,
        private readonly AuthService $auth,
    ) {}

    /**
     * `POST forgot-password`
     *
     * **Always 200, always the same sentence.** Whether the address is on file
     * is not this endpoint's to disclose: answering differently would let
     * anybody ask which of their competitors' staff have accounts here. The
     * wording says what was done *if* the address is known, which is true
     * either way and reads as an answer rather than a dodge.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink((string) $request->validated('email'));

        return $this->payload([
            'message' => 'If that address has an account, a reset link is on its way to it.',
        ]);
    }

    /** `POST reset-password` — the form the emailed link opens. */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwords->reset(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return $this->payload([
            'message' => 'Your password has been changed. Sign in with the new one.',
        ]);
    }

    /**
     * `POST me/password` — changing it while signed in.
     *
     * Answers 204 rather than re-issuing a token or a cookie. The session that
     * made this call is still good: the person proved they hold the current
     * password, so there is nothing to re-establish and nothing to hand back.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $this->passwords->change(
            $this->auth->requireUser($request),
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
        );

        return $this->noContent();
    }
}

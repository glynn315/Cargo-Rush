<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Setting a new password from a link in an email.
 *
 * The address travels with the token because the person following this link
 * has not signed in — there is no session to read it from. It is not the
 * secret; the token is, and it is single-use and expires.
 */
class ResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:160'],
            // The same strength registration asks for. A password chosen
            // through a reset is not a lesser password.
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}

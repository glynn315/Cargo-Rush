<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Changing your own password while signed in.
 *
 * The endpoint every account seeded with a shared starting password needs, and
 * that the manual has been telling people to use since before it existed.
 */
class ChangePasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Proof that the person at the keyboard is the account holder.
             *
             * Not a formality: a signed-in session on an unlocked laptop in a
             * shared office is exactly the situation where somebody else
             * changes the password and takes the account.
             */
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'That is the password you already have.',
        ];
    }
}

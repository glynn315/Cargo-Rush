<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * Asking for a reset link.
 */
class ForgotPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            /**
             * Validated as an address, and deliberately **not** checked against
             * `users`.
             *
             * An `exists` rule here would turn a 422 into an oracle: send an
             * address, read the response, learn whether that person has an
             * account on this platform. For a system hosting hauliers who
             * compete with each other, that is a question worth asking and not
             * one this endpoint should answer. The controller says the same
             * thing whether or not the address is known.
             */
            'email' => ['required', 'email', 'max:160'],
        ];
    }
}

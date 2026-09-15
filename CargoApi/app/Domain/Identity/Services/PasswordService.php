<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Getting back in, and changing the password once you are.
 *
 * Until this existed the system had no answer to a forgotten password. Every
 * account was created by somebody else — a seeder, `cargo:user`, or an
 * administrator on the roster screen — so recovery was a person with terminal
 * access running a command. That is workable for one office and impossible the
 * moment strangers can register their own company.
 *
 * **None of this is scoped to a company, and that is the design rather than an
 * oversight.** `password_reset_tokens` is keyed by email address, and an
 * address belongs to exactly one account system-wide — the same decision that
 * lets the login form stay two fields. So a token identifies the account, the
 * account names the company, and there is no tenant to establish before any of
 * it works. A reset flow that needed to know the company first would have to
 * carry one in the link, and a link that names a company is one that invites
 * being edited.
 */
class PasswordService
{
    /**
     * Send a reset link, and say nothing about whether the address is known.
     *
     * The response is the same either way. Anything else turns this endpoint
     * into a way of asking *does this person have an account here* — which for
     * a platform hosting competing hauliers is a real question somebody would
     * like answered, and one this system should not answer.
     *
     * Laravel's broker is doing the work behind it: single-use tokens, hashed
     * at rest, expiring on `auth.passwords.users.expire`, and its own throttle
     * so a held-down button does not send fifty emails.
     */
    public function sendResetLink(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }

    /**
     * Set a new password from a reset token.
     *
     * Three things happen together on success, and all three matter:
     *
     *   The password is replaced. The `hashed` cast on the model does the
     *   hashing, so there is no plain value written anywhere.
     *
     *   `remember_token` is rotated — the framework's own step, and the reason
     *   is that a stolen "remember me" cookie would otherwise survive the
     *   reset that was performed to shut it out.
     *
     *   **Every API token is revoked.** This part is ours. A driver's handset
     *   holds a long-lived bearer token, and somebody resetting a password is
     *   often somebody who believes their account is compromised. Leaving
     *   those alive would mean the reset changed nothing for the one attacker
     *   it was meant to remove.
     *
     * @throws ValidationException When the token is wrong, used or expired.
     */
    public function reset(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user) use ($password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // On the token rather than the password: the password was fine,
            // the link was not. Pointing at the wrong field sends somebody to
            // choose a different password over and over.
            throw ValidationException::withMessages([
                'token' => ['This reset link is no longer valid. Ask for a new one.'],
            ]);
        }
    }

    /**
     * Change your own password while signed in.
     *
     * The current one is required, and not as a formality: a signed-in session
     * on an unlocked laptop is exactly the situation where somebody else
     * changes the password and takes the account. Knowing the old one is what
     * proves the person at the keyboard is the account holder.
     *
     * Other sessions and devices are **kept**, unlike a reset. Somebody
     * choosing a stronger password on purpose has not lost control of
     * anything, and signing their own phone out for it would teach them not to
     * bother next time.
     *
     * @throws ValidationException
     */
    public function change(User $user, string $current, string $password): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['That is not your current password.'],
            ]);
        }

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();
    }
}

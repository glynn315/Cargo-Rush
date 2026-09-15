<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\DTO\CredentialsData;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\UserRepository;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum, both ways.
 *
 * One `POST /login`: with a `device_name` the caller gets a bearer token
 * (cargoApp), without one it gets the SPA session cookie (CargoUI). That is
 * DESIGN.md section 7.4, and it is the only place either is issued.
 *
 * It is also where a request first learns which company it is. Login is public,
 * so `BindTenant` has not run and nothing is scoped yet — the lookup below is
 * deliberately across all companies, because an email address is the one thing
 * in this system that identifies an account without already knowing the tenant.
 * From the moment the password checks out, the company is in force and every
 * read after it is filtered.
 */
class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array{user: User, token: string|null}
     *
     * @throws ValidationException
     */
    public function login(CredentialsData $credentials, Request $request): array
    {
        $user = $this->users->findByEmail($credentials->email);

        // One message for both a wrong address and a wrong password, so the
        // response cannot be used to find out which accounts exist.
        if ($user === null || ! Hash::check($credentials->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $this->enterCompany($user);

        return ['user' => $user, 'token' => $this->establish($user, $credentials->device_name, $request)];
    }

    /**
     * Put the account's company in force, or refuse the sign-in.
     *
     * Two failures, and they are told apart on purpose. A suspended company is
     * a billing conversation and the message says so; an account with no
     * company at all is a broken row and needs an administrator. Answering
     * both with "these credentials do not match our records" would send
     * somebody to reset a password that was never the problem.
     *
     * Unlike the credentials check above, saying too much here costs nothing:
     * whoever sees these has already proved the password.
     *
     * @throws ValidationException
     */
    private function enterCompany(User $user): void
    {
        $company = $user->company;

        // A shipper who registered and has not yet picked a haulier signs in
        // normally — there is simply no company to put in force, so the request
        // is scoped to one no row has. `BindTenant` does the same on every call
        // after this one. Refusing them would be refusing an account this
        // application deliberately creates.
        if ($company === null && $user->awaitingCarrier()) {
            $this->tenant->nobody();

            return;
        }

        if ($company === null) {
            throw ValidationException::withMessages([
                'email' => ['This account is not attached to a company. Ask an administrator to reattach it.'],
            ]);
        }

        if (! $company->isActive()) {
            throw ValidationException::withMessages([
                'email' => [sprintf('%s is suspended. Contact support to reactivate the account.', $company->name)],
            ]);
        }

        // Everything after this point — the permission list stamped onto a
        // token, the role the response reports — reads rows that are now
        // scoped to this company.
        $this->tenant->set($company);
    }

    /**
     * Issue whichever credential the caller asked for.
     *
     * Split out of `login()` so registration can sign its new owner in without
     * making them retype the password they chose a moment ago, and without
     * either path growing its own copy of the token-versus-cookie rule.
     *
     * The company must already be in force when this runs: the abilities burnt
     * into a token come from `permissions()`, which reads the account's role
     * row, and that row is one company's.
     *
     * @return string|null The plain-text token, or null when a cookie was set.
     *
     * @throws ValidationException
     */
    public function establish(User $user, ?string $deviceName, Request $request): ?string
    {
        if ($deviceName !== null && $deviceName !== '') {
            // A fresh login for a device replaces that device's old token
            // rather than stacking another one on the pile.
            $user->tokens()->where('name', $deviceName)->delete();

            return $user->createToken($deviceName, $user->permissions())->plainTextToken;
        }

        // No session store means Sanctum did not treat this as a first-party
        // request — the caller's host is not in SANCTUM_STATEFUL_DOMAINS. There
        // is no cookie to set, and calling session() here would be a 500 with a
        // stack trace instead of the one sentence that fixes it.
        if (! $request->hasSession()) {
            throw ValidationException::withMessages([
                'device_name' => [
                    'This origin cannot use cookie authentication. Send a device_name to '
                    .'receive a token, or add this host to SANCTUM_STATEFUL_DOMAINS.',
                ],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return null;
    }

    public function logout(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        /**
         * Token auth: drop just the token that made this call. Session auth:
         * end the session. Doing both would log a driver out of every device.
         *
         * The test is `instanceof PersonalAccessToken`, not `!== null`. A
         * cookie-authenticated request also has a "current access token" —
         * Sanctum hands it a `TransientToken`, which is a marker saying *this
         * one came in on a session* and not a row that can be deleted. Asking
         * whether it is null therefore answers yes for both styles, and the
         * SPA's sign-out went down the token branch and died on a method
         * `TransientToken` does not have.
         */
        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * @throws AuthenticationException
     */
    public function requireUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\DTO\RegistrationData;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registering a company — the first thing that happens on this platform.
 *
 * Four steps, and they are one transaction because every partial outcome is
 * worse than a failed registration. A company with no administrator is a firm
 * locked out of its own account. An administrator with no roles seeded holds a
 * role key that matches no row, so `permissions()` falls back to the enum and
 * the access screens open on an empty table. Both would need a developer to
 * unpick, and the person affected has no way to ask for one — they have not got
 * in yet.
 *
 * The new account is signed in as this finishes. Registering and then being
 * shown a login form is asking somebody to type the password they chose four
 * seconds ago, and the system already knows it is them.
 */
class RegistrationService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly CompanyProvisioner $provisioner,
        private readonly AuthService $auth,
    ) {}

    /**
     * @return array{company: Company, user: User, token: string|null}
     */
    public function register(RegistrationData $data, Request $request): array
    {
        /** @var array{company: Company, user: User} $created */
        $created = DB::transaction(function () use ($data): array {
            $company = Company::create([
                ...$data->companyAttributes(),
                // Derived, never submitted. It is a handle rather than a
                // choice, and one the registering user has no reason to care
                // about — see `Company::codeFor()`.
                'code' => Company::codeFor($data->company_name),
            ]);

            // Roles have to exist before an account can hold one.
            $this->provisioner->provision($company);

            // Inside the new company, so `company_id` is stamped by the model
            // layer rather than being passed in — the same path every other
            // write in the system takes.
            $user = $this->tenant->use($company, fn (): User => User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                /**
                 * The administrator, and it has to be.
                 *
                 * Whoever registers is the only person in the company, so any
                 * narrower role would leave nobody able to create the second
                 * account — including their own replacement. They can hand the
                 * role on and step down afterwards; they cannot grant
                 * themselves one they were never given.
                 */
                'role' => Role::Administrator->value,
            ]));

            return ['company' => $company, 'user' => $user];
        });

        /**
         * The rest of the request runs as the company that was just made, and
         * this has to happen *before* the token is issued rather than after.
         *
         * The abilities burnt into a token come from `permissions()`, which
         * reads the account's role row — and `roles.key` is only unique within
         * a company now. With nothing in force, `administrator` would match
         * whichever company's row the database happened to return first.
         *
         * The same applies to the response: `MeResource` asks for permissions
         * too, off the same rows.
         */
        $this->tenant->set($created['company']);

        // Outside the transaction on purpose: issuing a token writes a row and
        // starting a session writes a cookie, and neither should be rolled back
        // by a failure in work that is already committed.
        $token = $this->auth->establish($created['user'], $data->device_name, $request);

        return [...$created, 'token' => $token];
    }
}

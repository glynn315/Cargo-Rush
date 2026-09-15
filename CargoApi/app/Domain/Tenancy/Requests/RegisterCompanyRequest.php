<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\DTO\RegistrationData;
use Illuminate\Validation\Rules\Password;

/**
 * The one public write in the system.
 *
 * Everything else is behind `auth:sanctum`, which is what makes the rules here
 * worth more than the usual: nothing has authenticated, so this is the only
 * thing standing between the open internet and a row in `companies`.
 */
class RegisterCompanyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:200'],

            /**
             * Where the yard, the store or the depot actually is.
             *
             * The field that turns a registration into a listing. `address` is
             * what a letter needs; a pin is what a customer standing beside a
             * pallet needs — the carrier list in the customer app sorts
             * hauliers by how far their pin is from the load and draws each one
             * on a map, and a company with no pin does not appear on it.
             *
             * Optional even so, and it has to be: a fleet is registered by
             * whoever is at a laptop, who may be nowhere near the yard, and a
             * required map on the sign-up form would be a form abandoned. It
             * can be dropped later on `PATCH company`.
             *
             * A pair or nothing. Half a coordinate is not a place.
             */
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'name' => ['required', 'string', 'max:120'],
            /**
             * Unique across the whole `users` table, not within a company.
             *
             * This is the rule the login form's two fields rest on: an address
             * belongs to exactly one account, so signing in with it can only
             * mean one company and there is nothing else to ask for. Relaxing
             * it later would mean adding a company field to every login screen
             * in both clients.
             */
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            /**
             * Confirmed, and held to the framework's defaults — length, and a
             * check against the known-breached list where the install can reach
             * it. This password is about to hold everything the company owns,
             * and it is chosen by somebody with no administrator to correct
             * them.
             */
            'password' => ['required', 'confirmed', Password::defaults()],

            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => 'Your company needs a name.',
            'latitude.required_with' => 'A longitude needs its latitude.',
            'longitude.required_with' => 'A latitude needs its longitude.',
            // The default ("The email has already been taken") reads as though
            // the address is unavailable, like a username. It is not — it means
            // there is already an account, and the fix is to sign in.
            'email.unique' => 'That address already has an account. Sign in instead.',
        ];
    }

    public function toData(): RegistrationData
    {
        return RegistrationData::fromArray($this->validated());
    }
}

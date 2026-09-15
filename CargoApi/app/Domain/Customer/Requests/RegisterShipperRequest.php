<?php

declare(strict_types=1);

namespace App\Domain\Customer\Requests;

use App\Domain\Customer\DTO\ShipperRegistrationData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A shipper signing themselves up — the second public write.
 *
 * The first is a haulier registering (`RegisterCompanyRequest`), and the rules
 * here are held to the same standard for the same reason: nothing has
 * authenticated, so this is all that stands between the open internet and a row
 * somebody else's office has to deal with.
 *
 * It asks for a person and a login, and nothing else. There is no carrier and
 * no pin on a store, and their absence is the point rather than an omission:
 *
 *   **Who carries the load** is a decision per load. It is asked on the request
 *   form, where the load exists, from the carriers near wherever it is going
 *   out from — and a shipper who registered here rather than with a company may
 *   answer differently every week. Asking at sign-up would freeze a choice
 *   nobody has the information to make yet, and would mean a firm browsing the
 *   platform had to commit to a haulier before it could see a price.
 *
 *   **Where the load is** is a decision per load for the same reason. A firm
 *   with two warehouses does not have *an* address, and one pinned once at
 *   sign-up would quietly become the origin of every request afterwards. The
 *   request form pins it — from the handset's own position, from a search, or
 *   from the map — which is where somebody actually knows the answer.
 *
 * So the account this creates belongs to no haulier yet. That is a real state
 * with a name (`User::awaitingCarrier()`) and it lasts until their first
 * request, which opens them an account on the carrier they pick.
 */
class RegisterShipperRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            /**
             * The customer, and the person. One field, because this registers a
             * customer rather than a business: the name typed here is both what
             * the login is called and what the haulier's books will be opened in
             * the name of. The office can rename the record later; nobody
             * signing up on a phone should have to enter it twice.
             */
            'name' => ['required', 'string', 'max:120'],

            /**
             * What the desk rings about a pickup.
             *
             * Optional, and kept on the login until there is a carrier to copy
             * it to — `customers.contact` cannot be filled in yet because there
             * is no `customers` row until they choose one. Worth asking for
             * here all the same: a customer who arrives through the app never
             * had the phone call that would have established it, and an email
             * address where a phone number should be is a request the desk
             * cannot confirm.
             */
            'contact_phone' => ['nullable', 'string', 'max:40'],

            /**
             * Unique across the whole `users` table, exactly as a haulier's
             * registration is. An address belongs to one account system-wide,
             * which is what lets the login form stay two fields and still know
             * which company — and which customer — to open.
             */
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name — it is what a carrier books you under.',
            // The default ("The email has already been taken") reads as though
            // the address is unavailable, like a username. It is not: there is
            // already an account, and the fix is to sign in.
            'email.unique' => 'That address already has an account. Sign in instead.',
        ];
    }

    public function toData(): ShipperRegistrationData
    {
        return ShipperRegistrationData::fromArray($this->validated());
    }
}

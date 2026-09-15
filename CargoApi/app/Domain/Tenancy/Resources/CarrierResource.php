<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\DTO\CarrierListing;
use App\Domain\Tenancy\Services\LogoStore;
use Illuminate\Http\Request;

/**
 * A haulier as a customer picking one sees it.
 *
 * Deliberately not `CompanyResource`. That one answers "whose system am I
 * looking at" for somebody already inside a company, and carries the account's
 * own contact details because the caller holds `company.manage` over them.
 * This one is read by a shipper about a firm they have never dealt with, so it
 * carries only what a haulier advertises: who they are, where the yard is, what
 * they could put on the road, and the number to ring.
 *
 * The three request-shaped values — the distance, the capacity and whether this
 * shipper already has an account — come off `CarrierListing`, because none of
 * them is a fact about the company on its own.
 *
 * @mixin CarrierListing
 */
class CarrierResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $company = $this->company;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'code' => $company->code,

            // Derived on read, never stored — the same rule the shell's logo
            // follows. Null means the client draws the company's initials.
            'logo_url' => app(LogoStore::class)->url($company->logo_path),

            'address' => $company->address,
            // The number a customer would ring to chase a pickup. The account
            // contact's name and email stay behind `company.manage`, where they
            // belong: a shipper choosing a carrier has no use for the name of
            // whoever pays their platform bill.
            'contact_phone' => $company->contact_phone,

            'latitude' => $company->latitude,
            'longitude' => $company->longitude,

            // Null when the customer did not say where they are, in which case
            // the list is alphabetical and the client says so rather than
            // printing "0.0 km away" for every card.
            'distance_km' => $this->distance_km,

            // What they could send out today: units not in the workshop, and
            // what those units can carry between them.
            'vehicles_ready' => $this->vehicles_ready,
            'capacity_kg' => $this->capacity_kg,

            // Have we worked with them before? The client leads with these,
            // because a shipper's first question about a list of carriers is
            // usually which of them already knows their account.
            'linked' => $this->linked,
        ];
    }
}

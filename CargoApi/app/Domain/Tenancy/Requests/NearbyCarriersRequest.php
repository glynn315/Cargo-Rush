<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Services\CarrierDirectory;

/**
 * "Which hauliers are near me?"
 *
 * A GET with a form request, unusually for this codebase, because the answer
 * hangs on a pair of coordinates and a bad pair is worth refusing with a
 * sentence rather than quietly searching around latitude zero — which is in the
 * Atlantic, and would return an empty list that looks like "nobody serves you".
 *
 * Everything is optional. A customer who has not granted location permission
 * still gets a list, alphabetically, because a carrier they can ring is more
 * use than a prompt they declined.
 */
class NearbyCarriersRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            // Each half needs the other. Half a coordinate is not a place, and
            // the two rules make the point where somebody sending one will see
            // it.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],

            // Kilometres. Capped where the service caps it, so the message
            // comes back as a field error rather than as a silently clamped
            // search.
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:'.CarrierDirectory::MAX_RADIUS_KM],

            // Matched against the company name and the yard address, for the
            // customer who already knows who they are looking for.
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'lat.required_with' => 'A longitude needs its latitude.',
            'lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    /** Where the customer is, or null if they did not say. */
    public function point(): ?array
    {
        if (! $this->filled('lat') || ! $this->filled('lng')) {
            return null;
        }

        return ['lat' => (float) $this->input('lat'), 'lng' => (float) $this->input('lng')];
    }

    public function radiusKm(): ?float
    {
        return $this->filled('radius_km') ? (float) $this->input('radius_km') : null;
    }

    public function search(): ?string
    {
        $term = trim((string) $this->input('search', ''));

        return $term === '' ? null : $term;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\DTO\CarrierListing;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which hauliers a shipper could send this load with.
 *
 * The one read in the system that is deliberately about the whole platform
 * rather than about one company's books. It touches exactly two tables:
 * `companies`, which has never been tenant-scoped because it *is* the tenants,
 * and a count over `vehicles`, which is scoped and so is read with the scope
 * lifted on purpose and for two aggregates.
 *
 * Nothing here is a company's private business. A name, a yard, a phone number
 * and "six units, 34 tonnes between them" is what a haulier paints on the side
 * of a truck; a customer choosing who to ask needs precisely that and nothing
 * more. No trips, no rates, no customers, no people.
 *
 * ## How near is worked out
 *
 * A bounding box in SQL throws out most of the table on an index, then the
 * survivors are measured properly and the ones outside the radius dropped — a
 * box is not a circle, so the corners have to be trimmed. The measurement is
 * straight-line, which under-reads a mountain road, and that is the honest
 * thing to show for it: this is a sort order, not an ETA.
 */
class CarrierDirectory
{
    /**
     * How far a customer is assumed to mean by "near", in kilometres.
     *
     * Wide, because freight is not a taxi: a haulier two provinces away is an
     * ordinary answer for a full truckload, and a radius tight enough to feel
     * local would show a shipper in Iponan an empty list.
     */
    public const DEFAULT_RADIUS_KM = 150.0;

    /** The most a caller may ask for. Past this it is not a search. */
    public const MAX_RADIUS_KM = 1000.0;

    /**
     * How many carriers a list may hold before it stops being a choice.
     *
     * The carriers the shipper already works with are added on top of this, so
     * a long-standing haulier is never pushed off the end of the list by a
     * newcomer that happens to be closer.
     */
    public const MAX_RESULTS = 25;

    /**
     * The list, nearest first.
     *
     * @param  string[]  $linkedCompanyIds  Companies this shipper already has an account with.
     * @return Collection<int, CarrierListing>
     */
    public function near(
        ?float $lat = null,
        ?float $lng = null,
        ?float $radiusKm = null,
        ?string $search = null,
        array $linkedCompanyIds = [],
        int $limit = self::MAX_RESULTS,
    ): Collection {
        $radius = min(self::MAX_RADIUS_KM, max(1.0, $radiusKm ?? self::DEFAULT_RADIUS_KM));
        $located = $lat !== null && $lng !== null;

        $found = $this->discoverable($lat, $lng, $radius, $search);

        if ($located) {
            $found = $found->filter(
                fn (Company $company): bool => (float) $company->distanceKmFrom($lat, $lng) <= $radius,
            );
        }

        $found = $found->take($limit);

        // The hauliers this shipper is already mid-conversation with, wherever
        // they are. A request filed with a carrier 400 km away has to stay
        // reachable, and a carrier that has since taken its pin down must not
        // become a company the customer can read invoices from and never book
        // with again.
        //
        // Not while searching, though: somebody who typed a name is asking a
        // question, and answering it with their usual haulier as well would be
        // a list that ignores what they typed.
        $linked = $search === null || trim($search) === ''
            ? $this->linked($linkedCompanyIds, $found->keys()->all())
            : new Collection;

        return $this->listings($found->concat($linked), $lat, $lng, $linkedCompanyIds)
            ->sortBy(fn (CarrierListing $listing): array => [$listing->sortKey(), $listing->company->name])
            ->values();
    }

    /**
     * One carrier a shipper has named, or a 404.
     *
     * The gate on filing a request: a company id in a payload is a company id
     * somebody can change, so it is resolved here against the same rule the
     * list is built from — discoverable, or already theirs. An id that is
     * neither is a 404 rather than a 403, for the reason the portal gives
     * everywhere else: saying "that company exists, but is not yours" is
     * itself the leak.
     *
     * @param  string[]  $linkedCompanyIds
     */
    public function resolve(string $companyId, array $linkedCompanyIds = []): Company
    {
        $company = Company::query()
            ->where('id', $companyId)
            ->where(function (Builder $query) use ($linkedCompanyIds): void {
                $query->where(function (Builder $pinned): void {
                    $pinned->whereNotNull('latitude')->whereNotNull('longitude');
                });

                if ($linkedCompanyIds !== []) {
                    $query->orWhereIn('id', $linkedCompanyIds);
                }
            })
            ->first();

        abort_if($company === null, 404, 'No carrier of that id is taking requests.');

        // Checked apart from the query so the message can say why. A suspended
        // haulier is a real company with nobody in the building to confirm the
        // pickup, and "not found" would send the customer hunting for a typo.
        abort_unless(
            $company->isActive(),
            422,
            sprintf('%s is not taking requests at the moment. Choose another carrier.', $company->name),
        );

        return $company;
    }

    /**
     * The pinned, active companies inside the box, keyed by id.
     *
     * @return Collection<string, Company>
     */
    private function discoverable(?float $lat, ?float $lng, float $radius, ?string $search): Collection
    {
        $term = trim((string) $search);

        return Company::query()
            ->discoverable()
            ->when($lat !== null && $lng !== null, function (Builder $query) use ($lat, $lng, $radius): void {
                $box = Geo::boundingBox((float) $lat, (float) $lng, $radius);

                $query->whereBetween('latitude', [$box['min_lat'], $box['max_lat']])
                    ->whereBetween('longitude', [$box['min_lng'], $box['max_lng']]);
            })
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('name', 'like', '%'.$term.'%')
                        ->orWhere('address', 'like', '%'.$term.'%');
                });
            })
            ->orderBy('name')
            // Enough to survive trimming the corners off the box and still
            // fill the page. The alternative is the whole table.
            ->limit(self::MAX_RESULTS * 4)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  string[]  $ids
     * @param  string[]  $except  Already on the list; adding them twice would duplicate the card.
     * @return Collection<string, Company>
     */
    private function linked(array $ids, array $except): Collection
    {
        $wanted = array_values(array_diff($ids, $except));

        if ($wanted === []) {
            return new Collection;
        }

        return Company::query()
            ->whereIn('id', $wanted)
            ->where('status', StatusValue::Active->value)
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<string, Company>  $companies
     * @param  string[]  $linkedCompanyIds
     * @return Collection<int, CarrierListing>
     */
    private function listings(
        Collection $companies,
        ?float $lat,
        ?float $lng,
        array $linkedCompanyIds,
    ): Collection {
        $fleet = $this->fleet($companies->keys()->all());

        return $companies->map(fn (Company $company): CarrierListing => new CarrierListing(
            company: $company,
            distance_km: $lat === null || $lng === null
                ? null
                : $this->round($company->distanceKmFrom((float) $lat, (float) $lng)),
            vehicles_ready: (int) ($fleet[$company->id]['units'] ?? 0),
            capacity_kg: (int) ($fleet[$company->id]['capacity_kg'] ?? 0),
            linked: in_array($company->id, $linkedCompanyIds, true),
        ))->values();
    }

    /**
     * How much each of these hauliers could put on the road, in one query.
     *
     * `acrossCompanies` because crossing companies is the whole point of this
     * endpoint, and this is the narrowest possible use of it: two aggregates
     * grouped by company, with no row ever leaving the query. A unit in the
     * workshop or retired is not capacity, so `active` and `available` are the
     * only two statuses that count.
     *
     * @param  string[]  $companyIds
     * @return array<string, array{units: int, capacity_kg: int}>
     */
    private function fleet(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        return Vehicle::acrossCompanies()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', [StatusValue::Active->value, StatusValue::Available->value])
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as units, coalesce(sum(capacity_kg), 0) as capacity_kg')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->company_id => [
                    'units' => (int) $row->units,
                    'capacity_kg' => (int) $row->capacity_kg,
                ],
            ])
            ->all();
    }

    /** One decimal place. A metre of precision on a straight-line guess is a lie. */
    private function round(?float $km): ?float
    {
        return $km === null ? null : round($km, 1);
    }
}

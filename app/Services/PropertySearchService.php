<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

class PropertySearchService
{
    private const int DEFAULT_GUESTS = 1;

    private const int DEFAULT_PER_PAGE = 15;

    /**
     * Every property with a live offer, cheapest first, its cheapest live offer loaded as
     * `bestOffer`. Ranked and paginated in SQL over offers, then handed out as properties.
     *
     * Query-string values stay strings after the `integer` rule, hence the casts.
     *
     * @param  array{check_in: string, check_out: string, guests?: int|string|null, city?: string|null, per_page?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, Property>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return Offer::query()
            ->select('offers.*')
            ->joinSub($this->rankedLiveOffers($filters), 'ranked', function (JoinClause $join): void {
                $join->on('ranked.id', '=', 'offers.id')->where('ranked.rn', '=', 1);
            })
            // A total order, or pages could overlap or skip rows.
            ->orderBy('offers.price')
            ->orderBy('offers.property_id')
            ->with(['property', 'supplier'])
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE))
            ->through(fn (Offer $offer): Property => $offer->property->setRelation('bestOffer', $offer));
    }

    /**
     * Live offers numbered by price within their property.
     *
     * @param  array{check_in: string, check_out: string, guests?: int|string|null, city?: string|null}  $filters
     */
    private function rankedLiveOffers(array $filters): Builder
    {
        $query = Offer::query()
            ->selectRaw('offers.id, ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price ASC, offers.id ASC) AS rn')
            ->where('offers.check_in', '=', $filters['check_in'])
            ->where('offers.check_out', '=', $filters['check_out'])
            ->where('offers.max_guests', '>=', (int) ($filters['guests'] ?? self::DEFAULT_GUESTS))
            ->bookable()
            ->toBase();

        if (($filters['city'] ?? null) !== null) {
            $query
                ->join('properties', 'properties.id', '=', 'offers.property_id')
                ->where('properties.city', '=', $filters['city']);
        }

        return $query;
    }
}

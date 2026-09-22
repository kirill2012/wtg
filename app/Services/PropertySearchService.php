<?php

namespace App\Services;

use App\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class PropertySearchService
{
    private const int DEFAULT_GUESTS = 1;

    private const int DEFAULT_PER_PAGE = 15;

    /**
     * The cheapest live offer of every matching property, cheapest first, paginated in SQL.
     *
     * The outer query keeps rank 1 of the ranking subquery, so a page of offers is a page
     * of properties. The paginator runs that subquery twice (count and page); fine at this
     * scale.
     *
     * Query-string values stay strings after the `integer` rule, hence the casts.
     *
     * @param  array{check_in: string, check_out: string, guests?: int|string|null, city?: string|null, per_page?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, Offer>
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
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE));
    }

    /**
     * Live offers numbered by price within their property. The city join is added only
     * when filtering, so the optimizer can otherwise start from the dates index.
     *
     * @param  array{check_in: string, check_out: string, guests?: int|string|null, city?: string|null}  $filters
     */
    private function rankedLiveOffers(array $filters): Builder
    {
        $query = DB::table('offers')
            ->selectRaw('offers.id, ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price ASC, offers.id ASC) AS rn')
            ->where('offers.check_in', '=', $filters['check_in'])
            ->where('offers.check_out', '=', $filters['check_out'])
            ->where('offers.max_guests', '>=', (int) ($filters['guests'] ?? self::DEFAULT_GUESTS))
            ->whereColumn('offers.available_units', '>', 'offers.reserved_units')
            ->where('offers.expires_at', '>', now());

        if (($filters['city'] ?? null) !== null) {
            $query
                ->join('properties', 'properties.id', '=', 'offers.property_id')
                ->where('properties.city', '=', $filters['city']);
        }

        return $query;
    }
}

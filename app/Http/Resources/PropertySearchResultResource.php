<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /api/properties: a property together with its best offer.
 *
 * Wraps an Offer, not a Property: the search query keeps exactly one offer per property
 * (rank 1 of the price ranking), so the offer *is* the property row and its `property`
 * relation supplies the property fields. Expects `property` and `supplier` to be loaded.
 * `City` keeps the capital letter of the task's contract; the column is `city`.
 *
 * @mixin Offer
 */
class PropertySearchResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->property->code,
            'name' => $this->property->name,
            'City' => $this->property->city,
            'best_offer' => [
                'id' => $this->id,
                'supplier' => $this->supplier->slug,
                'price' => $this->price,
                'currency' => $this->currency,
                // What is still bookable, not the supplier's raw column.
                'available_units' => $this->free_units,
                'expires_at' => $this->expires_at,
            ],
        ];
    }
}

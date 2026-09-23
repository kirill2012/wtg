<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /api/properties. Wraps the property's best offer, not the property.
 * Expects `property` and `supplier` loaded.
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
                // Available minus reserved.
                'available_units' => $this->free_units,
                'expires_at' => $this->expires_at,
            ],
        ];
    }
}

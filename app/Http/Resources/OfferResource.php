<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An offer as a client can book it. Expects `supplier` loaded.
 *
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier->slug,
            'price' => $this->price,
            'currency' => $this->currency,
            // Available minus reserved.
            'available_units' => $this->free_units,
            'expires_at' => $this->expires_at,
        ];
    }
}

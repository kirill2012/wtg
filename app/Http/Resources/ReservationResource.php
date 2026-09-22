<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The body of POST /api/offers/{offer}/reservations: the reservation, flat.
 *
 * What is returned is the snapshot the reservation holds, not the offer's current state:
 * the offer may have been re-imported since. Expects the `property` relation to be loaded.
 *
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
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
            'offer_id' => $this->offer_id,
            'client_reference' => $this->client_reference,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'property_code' => $this->property->code,
            // Dates, not moments: a bare Carbon would serialise as `2026-10-10T00:00:00Z`.
            'check_in' => $this->check_in->toDateString(),
            'check_out' => $this->check_out->toDateString(),
            'price' => $this->price,
            'currency' => $this->currency,
            'created_at' => $this->created_at,
        ];
    }
}

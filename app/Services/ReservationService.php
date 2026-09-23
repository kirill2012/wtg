<?php

namespace App\Services;

use App\Exceptions\ClientReferenceTakenException;
use App\Exceptions\OfferUnavailableException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    /**
     * Book one unit of the offer, or return the reservation made earlier with the same
     * client_reference.
     *
     * The offer's row lock, held until commit, prevents overselling. It is taken before the
     * idempotency lookup, so a resend that waited on it sees the winner's reservation.
     * Retried on a deadlock: the method is idempotent.
     *
     * @param  array{client_reference: string, customer_name: string, customer_email: string}  $data
     */
    public function reserve(Offer $offer, array $data): Reservation
    {
        return DB::transaction(function () use ($offer, $data): Reservation {
            $locked = Offer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

            // A plain lookup: a locking one on a missing reference would gap-lock.
            $existing = Reservation::query()->where('client_reference', $data['client_reference'])->first();

            if ($existing !== null) {
                return $this->sameOfferOrConflict($existing, $locked);
            }

            if ($locked->expires_at->lessThanOrEqualTo(now())) {
                throw OfferUnavailableException::expired();
            }

            if ($locked->free_units < 1) {
                throw OfferUnavailableException::soldOut();
            }

            // Insert first: MySQL rolls back only the failed statement, so an earlier
            // increment would survive the caught conflict.
            try {
                $reservation = Reservation::query()->create([
                    'offer_id' => $locked->id,
                    'client_reference' => $data['client_reference'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    // What was booked, whatever later imports do to the offer.
                    'property_id' => $locked->property_id,
                    'check_in' => $locked->check_in,
                    'check_out' => $locked->check_out,
                    'price' => $locked->price,
                    'currency' => $locked->currency,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Taken by a concurrent request; only a locking read sees it. Shared: the
                // failed insert already holds an S lock on the key, and upgrading it to X
                // would deadlock two losers of the same race.
                $existing = Reservation::query()
                    ->where('client_reference', $data['client_reference'])
                    ->sharedLock()
                    ->firstOrFail();

                return $this->sameOfferOrConflict($existing, $locked);
            }

            $locked->increment('reserved_units');

            return $reservation;
        }, attempts: 3);
    }

    /**
     * The same reference on another offer is a different request, not a resend.
     */
    private function sameOfferOrConflict(Reservation $reservation, Offer $offer): Reservation
    {
        if (! $reservation->offer()->is($offer)) {
            throw new ClientReferenceTakenException;
        }

        return $reservation;
    }
}

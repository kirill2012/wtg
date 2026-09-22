<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReservationService
{
    /**
     * Book one unit of the offer, or return the reservation an earlier request with the
     * same client_reference already made.
     *
     * Overselling is prevented by the row lock on the offer, held until commit: a second
     * request for the last unit waits, then reads `reserved_units` already incremented. The
     * unique `client_reference` serves idempotency only.
     *
     * The lock precedes the idempotency lookup: under REPEATABLE READ the snapshot is fixed
     * by the first plain read, so it then includes a competing request's commit.
     *
     * Retried on a deadlock or lock wait timeout, which would otherwise surface as a 500;
     * safe because the method is idempotent.
     *
     * @param  array{client_reference: string, customer_name: string, customer_email: string}  $data
     */
    public function reserve(Offer $offer, array $data): Reservation
    {
        return DB::transaction(function () use ($offer, $data): Reservation {
            $locked = Offer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

            // A plain lookup: locking a missing reference would gap-lock its range and
            // deadlock with a neighbouring insert.
            $existing = Reservation::query()->where('client_reference', $data['client_reference'])->first();

            if ($existing !== null) {
                return $this->sameOfferOrConflict($existing, $locked);
            }

            if ($locked->expires_at->lessThanOrEqualTo(now())) {
                abort(Response::HTTP_CONFLICT, 'The offer has expired.');
            }

            if ($locked->free_units < 1) {
                abort(Response::HTTP_CONFLICT, 'The offer is sold out.');
            }

            // Insert before increment: MySQL rolls back only the failed statement, so an
            // earlier increment would survive the caught conflict below.
            try {
                $reservation = Reservation::query()->create([
                    'offer_id' => $locked->id,
                    'client_reference' => $data['client_reference'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    // A snapshot: a later import may reprice, move or reschedule the offer.
                    'property_id' => $locked->property_id,
                    'check_in' => $locked->check_in,
                    'check_out' => $locked->check_out,
                    'price' => $locked->price,
                    'currency' => $locked->currency,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request committed this reference for another offer. Only a
                // locking read sees it past our snapshot; createOrFirst falls back to a plain one.
                $existing = Reservation::query()
                    ->where('client_reference', $data['client_reference'])
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->sameOfferOrConflict($existing, $locked);
            }

            $locked->increment('reserved_units');

            return $reservation;
        }, attempts: 3);
    }

    /**
     * The same reference on another offer is a different request: returning the old
     * reservation would silently swap what was booked.
     */
    private function sameOfferOrConflict(Reservation $reservation, Offer $offer): Reservation
    {
        if (! $reservation->offer()->is($offer)) {
            abort(Response::HTTP_CONFLICT, 'This client_reference already belongs to a reservation of another offer.');
        }

        return $reservation;
    }
}

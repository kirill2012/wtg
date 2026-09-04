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
     * Overselling is prevented by one thing only: the row lock on the offer, held from the
     * locking read to the commit. A second request for the last unit waits on it and then
     * reads `reserved_units` already incremented. The unique key on `client_reference` is
     * about idempotency of a resent request, not a second line of defence: two concurrent
     * requests for the last unit carry different references and both pass it.
     *
     * The lock is taken before the idempotency lookup on purpose. Under REPEATABLE READ the
     * transaction's snapshot is fixed by its first plain read; taken after the lock, it
     * includes whatever a competing request for the same offer committed while we waited,
     * so a resent request that lost that race finds the winner's reservation instead of a
     * spurious "sold out".
     *
     * State problems end in `abort(409)` right here: one body shape with the default 404
     * and 422, and no exception hierarchy for two cases.
     *
     * The transaction is retried on a concurrency error: a deadlock or a lock wait timeout
     * is transient, and unretried it surfaces as a 500 where a 201 or a 409 was reachable.
     * Replaying is safe because `client_reference` makes the method idempotent.
     *
     * @param  array{client_reference: string, customer_name: string, customer_email: string}  $data
     */
    public function reserve(Offer $offer, array $data): Reservation
    {
        return DB::transaction(function () use ($offer, $data): Reservation {
            $locked = Offer::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

            // A plain lookup, not a locking one: a locking read of a reference that does not
            // exist yet would gap-lock the index around it and deadlock with the insert of a
            // neighbouring reference from another request.
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

            // The insert goes before the increment: MySQL rolls back only the failed
            // statement on a duplicate key, so an increment made earlier would survive the
            // caught conflict below and a unit would be gone without a reservation.
            try {
                $reservation = Reservation::query()->create([
                    'offer_id' => $locked->id,
                    'client_reference' => $data['client_reference'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    // A snapshot: the supplier may reprice the offer afterwards.
                    'price' => $locked->price,
                    'currency' => $locked->currency,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same reference landed from a concurrent request between our lookup and
                // our insert — for another offer, since this offer's lock serialises the rest.
                // The locking read sees the committed row whatever our snapshot says, which
                // is why createOrFirst, whose fallback is a plain read, is not used here.
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
     * Idempotency means "the same request gives the same result". The same reference on a
     * different offer is a different request, and answering it with the old reservation
     * would silently swap the subject of the deal.
     */
    private function sameOfferOrConflict(Reservation $reservation, Offer $offer): Reservation
    {
        if (! $reservation->offer()->is($offer)) {
            abort(Response::HTTP_CONFLICT, 'This client_reference already belongs to a reservation of another offer.');
        }

        return $reservation;
    }
}

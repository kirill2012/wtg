<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function store(StoreReservationRequest $request, Offer $offer, ReservationService $reservationService): JsonResponse
    {
        $reservation = $reservationService->reserve($offer, $request->validated());

        // A resent request gets the reservation it made the first time, with 200 rather than 201.
        return ReservationResource::make($reservation)
            ->response()
            ->setStatusCode($reservation->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}

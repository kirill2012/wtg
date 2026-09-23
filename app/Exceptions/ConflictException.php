<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that clashes with the current state: answered 409 `{"message": ...}`, with no
 * trace even when APP_DEBUG is on, and not logged, since it is the client's to resolve.
 */
abstract class ConflictException extends Exception implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
    }
}

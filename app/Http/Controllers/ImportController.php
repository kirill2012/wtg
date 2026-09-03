<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportAcceptedResource;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ImportController extends Controller
{
    public function store(StoreImportRequest $request, ImportService $importService): JsonResponse
    {
        $import = $importService->accept($request->validated());

        return ImportAcceptedResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}

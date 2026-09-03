<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportAcceptedResource;
use App\Http\Resources\ImportResource;
use App\Models\Import;
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
            ->setStatusCode(Response::HTTP_ACCEPTED)
            ->header('Location', route('imports.show', $import));
    }

    public function show(Import $import): ImportResource
    {
        return ImportResource::make($import->loadMissing('supplier'));
    }
}

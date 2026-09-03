<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertySearchResultResource;
use App\Services\PropertySearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function index(SearchPropertiesRequest $request, PropertySearchService $propertySearch): AnonymousResourceCollection
    {
        // Page links must carry the search itself, or following `next` would end in a 422.
        return PropertySearchResultResource::collection(
            $propertySearch->search($request->validated())->withQueryString(),
        );
    }
}

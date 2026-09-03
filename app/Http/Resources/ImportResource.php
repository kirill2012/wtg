<?php

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The current state of an asynchronous import, as polled via GET /api/imports/{import}.
 *
 * Fields are listed explicitly rather than spread from `parent::toArray()`: the moment
 * serializer in AppServiceProvider applies to Carbon attributes returned as-is, not to
 * the strings `Model::toArray()` produces. Expects the `supplier` relation to be loaded.
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
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
            'external_import_id' => $this->external_import_id,
            'sent_at' => $this->sent_at,
            'status' => $this->status,
            'total_offers' => $this->total_offers,
            'processed_offers' => $this->processed_offers,
            'error' => $this->error,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The state of an import, as polled via GET /api/imports/{import}. Expects `supplier` loaded.
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

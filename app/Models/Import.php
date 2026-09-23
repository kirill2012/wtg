<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'external_import_id',
        'sent_at',
        'status',
        'payload',
        'total_offers',
        'processed_offers',
        'error',
        'completed_at',
        'claimed_by',
    ];

    /**
     * Mirrors the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ImportStatus::Pending->value,
        'processed_offers' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'status' => ImportStatus::class,
            'payload' => 'array',
            'total_offers' => 'integer',
            'processed_offers' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The offers this import wrote last: a later import that updates an offer takes it over,
     * so this is not every offer the payload carried.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}

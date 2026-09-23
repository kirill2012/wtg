<?php

namespace App\Models;

use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    /**
     * The one currency every price is in: search compares raw minor units, no conversion.
     */
    public const string CURRENCY = 'EUR';

    /**
     * No `reserved_units`: reservations write it, and an import only recounts it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'property_id',
        'import_id',
        'external_id',
        'sent_at',
        'check_in',
        'check_out',
        'max_guests',
        'price',
        'currency',
        'available_units',
        'expires_at',
    ];

    /**
     * Mirrors the database default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reserved_units' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'check_in' => 'date:Y-m-d',
            'check_out' => 'date:Y-m-d',
            'max_guests' => 'integer',
            'price' => 'integer',
            'available_units' => 'integer',
            'reserved_units' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Offers that can still be booked: not expired and with a unit left. The SQL form of
     * `! isExpired() && ! isSoldOut()`; the two must change together.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function bookable(Builder $query): void
    {
        $query
            ->where($query->qualifyColumn('expires_at'), '>', now())
            ->whereColumn($query->qualifyColumn('available_units'), '>', $query->qualifyColumn('reserved_units'));
    }

    /**
     * Closed from the second it expires.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    public function isSoldOut(): bool
    {
        return $this->free_units < 1;
    }

    /**
     * Units still bookable, never below zero.
     */
    protected function freeUnits(): Attribute
    {
        return Attribute::get(fn (): int => max(0, $this->available_units - $this->reserved_units));
    }
}

<?php

namespace App\Models;

use Database\Factories\OfferFactory;
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
     * `reserved_units` is deliberately absent: it belongs to the application, and the
     * booking flow moves it with `increment()`. Keeping it out of mass assignment means an
     * import that tries to write it fails loudly instead of overwriting reservations.
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
     * Mirrors the database default so `free_units` works on an offer that has not been
     * persisted (or refreshed) yet.
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
     * Units still open for booking: what the supplier published minus what the
     * application has reserved, never below zero (a supplier may lower `available_units`
     * under the reserved count; existing reservations stay).
     *
     * Deliberately not named `available_units`: an accessor by that name would shadow the
     * raw column the booking logic reads under the row lock.
     */
    protected function freeUnits(): Attribute
    {
        return Attribute::get(fn (): int => max(0, $this->available_units - $this->reserved_units));
    }
}

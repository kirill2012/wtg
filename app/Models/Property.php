<?php

namespace App\Models;

use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `bestOffer` is set by PropertySearchService, not defined as a relation: which offer is
 * best depends on the search's filters.
 *
 * @property-read Offer|null $bestOffer
 */
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'city',
    ];

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}

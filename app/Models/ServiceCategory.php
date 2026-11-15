<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Provider profiles grouped under this category.
     */
    public function providerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            ProviderProfile::class,
            'provider_categories',
            'service_category_id',
            'provider_profile_id'
        )->withTimestamps();
    }

    /**
     * Quote requests classified under this category.
     */
    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }
}

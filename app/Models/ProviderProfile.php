<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderProfile extends Model
{
    protected $fillable = [
        'user_id',
        'professional_description',
        'location',
        'city_id',
        'average_rating',
        'compliance_index',
        'completed_jobs_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'average_rating' => 'decimal:1',
            'compliance_index' => 'integer',
            'completed_jobs_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The user that owns this provider profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The city this provider profile is located in.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Service categories this provider offers.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceCategory::class,
            'provider_categories',
            'provider_profile_id',
            'service_category_id'
        )->withTimestamps();
    }

    /**
     * Portfolios belonging to this provider, oldest first.
     */
    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class)->orderBy('created_at')->orderBy('id');
    }
}

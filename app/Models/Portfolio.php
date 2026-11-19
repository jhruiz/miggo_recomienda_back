<?php

namespace App\Models;

use Database\Factories\PortfolioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    /** @use HasFactory<PortfolioFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'service_category_id',
    ];

    /**
     * likes_count is managed by the system, never mass-assigned.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
        ];
    }

    /**
     * Portfolios eligible for the wall: active provider and at least one ready photo.
     *
     * @param  Builder<Portfolio>  $query
     * @return Builder<Portfolio>
     */
    public function scopeVisibleOnWall(Builder $query): Builder
    {
        return $query
            ->whereHas('providerProfile', fn (Builder $profile) => $profile->where('is_active', true))
            ->whereHas('items', fn (Builder $item) => $item->whereNotNull('portfolio_items.conversions_ready_at'));
    }

    /**
     * Likes given to this portfolio.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(PortfolioLike::class);
    }

    /**
     * Saves of this portfolio.
     */
    public function saves(): HasMany
    {
        return $this->hasMany(PortfolioSave::class);
    }

    protected static function booted(): void
    {
        // Mantiene name_normalized sincronizado para la unicidad sin distinguir mayúsculas.
        static::saving(function (Portfolio $portfolio): void {
            $portfolio->name_normalized = self::normalizeName((string) $portfolio->name);
        });
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * The provider profile that owns this portfolio.
     */
    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class);
    }

    /**
     * The service category of this portfolio (null for legacy uncategorized portfolios).
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /**
     * Photos in this portfolio, ordered by id (the first one is the cover).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->orderBy('id');
    }
}

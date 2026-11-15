<?php

namespace App\Models;

use Database\Factories\PortfolioFactory;
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
    ];

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
     * Photos in this portfolio, ordered by id (the first one is the cover).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->orderBy('id');
    }
}

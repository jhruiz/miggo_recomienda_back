<?php

namespace App\Models;

use Database\Factories\PortfolioItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PortfolioItem extends Model implements HasMedia
{
    /** @use HasFactory<PortfolioItemFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'description',
    ];

    /**
     * conversions_ready_at is managed by the system, never mass-assigned.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversions_ready_at' => 'datetime',
        ];
    }

    /**
     * The portfolio this item belongs to.
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /**
     * Register the media collection for the portfolio image.
     * Accepts JPG/PNG/WEBP; single-file collection per item.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('portfolio')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Register the thumb (400x400 crop) and medium (max width 1200, never upscaled)
     * WebP conversions for portfolio images. The original is kept untouched.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 400, 400)
            ->format('webp')
            ->performOnCollections('portfolio')
            ->queued();

        $this->addMediaConversion('medium')
            ->fit(Fit::Max, 1200, 10000)
            ->format('webp')
            ->performOnCollections('portfolio')
            ->queued();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PortfolioItem extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'description',
    ];

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
     * Register a thumbnail conversion for portfolio images.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(400)
            ->height(400)
            ->nonQueued();
    }
}

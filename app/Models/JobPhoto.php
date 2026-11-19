<?php

namespace App\Models;

use Database\Factories\JobPhotoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class JobPhoto extends Model implements HasMedia
{
    /** @use HasFactory<JobPhotoFactory> */
    use HasFactory, InteractsWithMedia;

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
     * Photos whose conversions are generated.
     *
     * @param  Builder<JobPhoto>  $query
     * @return Builder<JobPhoto>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->whereNotNull('job_photos.conversions_ready_at');
    }

    /**
     * The job this photo belongs to.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Single-file collection accepting JPG/PNG/WEBP.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('job_photo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Thumb (400x400 crop) and medium (max width 1200, never upscaled) WebP conversions.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 400, 400)
            ->format('webp')
            ->performOnCollections('job_photo')
            ->queued();

        $this->addMediaConversion('medium')
            ->fit(Fit::Max, 1200, 10000)
            ->format('webp')
            ->performOnCollections('job_photo')
            ->queued();
    }
}

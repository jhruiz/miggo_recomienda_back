<?php

namespace App\Listeners;

use App\Models\JobPhoto;
use App\Models\PortfolioItem;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

class MarkPortfolioItemConversionsReady
{
    /**
     * Model class => Media Library collection that feeds it.
     *
     * @var array<class-string<Model>, string>
     */
    private const TARGETS = [
        PortfolioItem::class => 'portfolio',
        JobPhoto::class => 'job_photo',
    ];

    /**
     * Mark the portfolio item or job photo as eligible once both WebP
     * conversions (thumb and medium) exist on disk. Idempotent.
     */
    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        $media = $event->media;

        $modelClass = $media->model_type;

        if (! isset(self::TARGETS[$modelClass]) || self::TARGETS[$modelClass] !== $media->collection_name) {
            return;
        }

        if (! in_array($event->conversion->getName(), ['thumb', 'medium'], true)) {
            return;
        }

        $thumbPath = $media->getPathRelativeToRoot('thumb');
        $mediumPath = $media->getPathRelativeToRoot('medium');

        if (! str_ends_with($thumbPath, '.webp') || ! str_ends_with($mediumPath, '.webp')) {
            return;
        }

        $disk = Storage::disk($media->conversions_disk);

        if (! $disk->exists($thumbPath) || ! $disk->exists($mediumPath)) {
            return;
        }

        $thumb = $this->dimensions($disk, $thumbPath);
        $medium = $this->dimensions($disk, $mediumPath);

        if ($thumb === null || $medium === null) {
            return;
        }

        $modelClass::query()
            ->whereKey($media->model_id)
            ->update([
                'thumb_width' => $thumb[0],
                'thumb_height' => $thumb[1],
                'medium_width' => $medium[0],
                'medium_height' => $medium[1],
                'conversions_ready_at' => now(),
            ]);
    }

    /**
     * Read the pixel dimensions from the file contents (works for local and S3 disks).
     *
     * @return array{0: int, 1: int}|null
     */
    private function dimensions(Filesystem $disk, string $path): ?array
    {
        $contents = $disk->get($path);

        if (! is_string($contents)) {
            return null;
        }

        $size = @getimagesizefromstring($contents);

        return $size === false ? null : [(int) $size[0], (int) $size[1]];
    }
}

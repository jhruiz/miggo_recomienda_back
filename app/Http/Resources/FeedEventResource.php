<?php

namespace App\Http\Resources;

use App\Enums\FeedSubjectType;
use App\Models\FeedEvent;
use App\Models\Job;
use App\Models\Portfolio;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento del historial de comportamiento. Espera `portfolio.serviceCategory`,
 * `portfolio.items.media`, `job.serviceCategory` y `job.photos.media` cargados.
 *
 * Nunca expone datos personales del Cliente de un Trabajo.
 *
 * @property FeedEvent $resource
 */
class FeedEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->resource;

        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'subject_type' => $event->subject_type?->value,
            'subject' => $this->subject($event),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string|null, thumb_url: string|null}|null
     */
    private function subject(FeedEvent $event): ?array
    {
        return match ($event->subject_type) {
            FeedSubjectType::Portfolio => $event->portfolio === null ? null : $this->portfolioSubject($event->portfolio),
            FeedSubjectType::Job => $event->job === null ? null : $this->jobSubject($event->job),
            null => null,
        };
    }

    /**
     * @return array{id: int, name: string|null, thumb_url: string|null}
     */
    private function portfolioSubject(Portfolio $portfolio): array
    {
        $cover = $portfolio->items
            ->first(fn ($item) => $item->conversions_ready_at !== null)
            ?->getFirstMedia(PortfolioService::MEDIA_COLLECTION);

        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'thumb_url' => $cover?->getUrl('thumb'),
        ];
    }

    /**
     * @return array{id: int, name: string|null, thumb_url: string|null}
     */
    private function jobSubject(Job $job): array
    {
        $category = $job->serviceCategory?->name;
        $cover = $job->photos
            ->first(fn ($photo) => $photo->conversions_ready_at !== null)
            ?->getFirstMedia('job_photo');

        return [
            'id' => $job->id,
            'name' => $category === null ? 'Trabajo' : 'Trabajo de '.$category,
            'thumb_url' => $cover?->getUrl('thumb'),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Job;
use App\Models\Rating;
use App\Support\ComplianceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarjeta del Muro de Trabajos (lista blanca explícita).
 *
 * Espera cargadas `serviceCategory`, `providerProfile.user`, `providerProfile.city`,
 * `photos.media` y `ratings`, y los atributos `liked_by_me`, `saved_by_me` y
 * `can_rate_by_me`. Nunca expone datos del Cliente ni de la solicitud.
 *
 * @property Job $resource
 */
class WallJobResource extends JsonResource
{
    public const CLIENT_LABEL = 'Cliente verificado';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->card();
    }

    /**
     * @return array<string, mixed>
     */
    protected function card(): array
    {
        $job = $this->resource;
        $category = $job->serviceCategory;
        $profile = $job->providerProfile;

        return [
            'id' => $job->id,
            'category' => $category === null ? null : [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'provider' => [
                'id' => $profile?->id,
                'name' => $profile?->user?->name,
                'city' => $profile?->city?->name,
                'compliance_index' => $profile?->compliance_index,
            ],
            'compliance_summary' => ComplianceSummary::fromDates(
                $job->estimated_delivery_date,
                $job->actual_completion_date,
            ),
            'client_label' => self::CLIENT_LABEL,
            'rating' => $this->clientRating(),
            'photos' => WallJobPhotoResource::collection($job->photos)->resolve(),
            'likes_count' => (int) $job->likes_count,
            'liked_by_me' => (bool) ($job->liked_by_me ?? false),
            'saved_by_me' => (bool) ($job->saved_by_me ?? false),
            'can_rate_by_me' => (bool) ($job->can_rate_by_me ?? false),
            'completed_at' => $job->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Solo la Calificación_Cliente ya revelada; la del Prestador nunca sale.
     *
     * @return array{score: int, comment?: string}|null
     */
    private function clientRating(): ?array
    {
        $job = $this->resource;

        /** @var Rating|null $rating */
        $rating = $job->ratings->first(fn (Rating $rating): bool => (int) $rating->rater_user_id === (int) $job->client_user_id
            && $rating->rated_at !== null
            && $rating->is_visible);

        if ($rating === null) {
            return null;
        }

        $data = ['score' => $rating->score];

        if ($rating->comment !== null && trim($rating->comment) !== '') {
            $data['comment'] = $rating->comment;
        }

        return $data;
    }
}

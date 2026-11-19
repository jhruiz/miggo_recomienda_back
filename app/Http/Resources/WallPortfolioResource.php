<?php

namespace App\Http\Resources;

use App\Models\Portfolio;
use App\Services\WallPortfolioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarjeta_Portafolio del Muro.
 *
 * Espera `serviceCategory`, `providerProfile.user`, `providerProfile.city` e `items`
 * (fotos listas con `media`, máximo 4) cargadas, y los atributos `ready_photos_count`,
 * `liked_by_me` y `saved_by_me` de withCount()/withExists().
 *
 * @property Portfolio $resource
 */
class WallPortfolioResource extends JsonResource
{
    public const LAYOUT_COVER = 'cover';

    public const LAYOUT_COLLAGE = 'collage';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $portfolio = $this->resource;
        $category = $portfolio->serviceCategory;
        $profile = $portfolio->providerProfile;
        $photosCount = (int) ($portfolio->ready_photos_count ?? 0);
        $isCollage = $photosCount >= 3;
        $visible = $isCollage
            ? $portfolio->items->take(WallPortfolioService::COVER_PHOTOS)
            : $portfolio->items->take(1);
        $alt = self::fallbackAlt($portfolio);

        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'category' => $category === null ? null : [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'provider' => [
                'id' => $profile->id,
                'name' => $profile->user->name,
                'city' => $profile->city?->name,
            ],
            'photos_count' => $photosCount,
            'layout' => $isCollage ? self::LAYOUT_COLLAGE : self::LAYOUT_COVER,
            'covers' => $visible
                ->map(fn ($item) => (new WallPortfolioPhotoResource($item, $alt))->toArray($request))
                ->values()
                ->all(),
            'likes_count' => (int) $portfolio->likes_count,
            'liked_by_me' => (bool) ($portfolio->liked_by_me ?? false),
            'saved_by_me' => (bool) ($portfolio->saved_by_me ?? false),
            'created_at' => $portfolio->created_at?->toIso8601String(),
        ];
    }

    public static function fallbackAlt(Portfolio $portfolio): string
    {
        $name = trim((string) $portfolio->name);

        if ($name !== '') {
            return $name;
        }

        $category = $portfolio->serviceCategory?->name;
        $provider = $portfolio->providerProfile?->user?->name;

        return 'Foto del trabajo de '.($provider ?? 'un prestador').($category !== null ? ' en '.$category : '');
    }
}

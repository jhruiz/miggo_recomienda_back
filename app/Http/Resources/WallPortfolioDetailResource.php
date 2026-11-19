<?php

namespace App\Http\Resources;

use App\Models\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalle_Portafolio: la tarjeta más la descripción y todas las fotos listas.
 * Los indicadores de Me gusta y Guardar son del Portafolio, no de cada foto.
 *
 * @property Portfolio $resource
 */
class WallPortfolioDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $portfolio = $this->resource;
        $category = $portfolio->serviceCategory;
        $profile = $portfolio->providerProfile;
        $alt = WallPortfolioResource::fallbackAlt($portfolio);

        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'description' => $portfolio->description,
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
            'photos_count' => (int) ($portfolio->ready_photos_count ?? $portfolio->items->count()),
            'photos' => $portfolio->items
                ->map(fn ($item) => (new WallPortfolioPhotoResource($item, $alt))->toArray($request))
                ->values()
                ->all(),
            'likes_count' => (int) $portfolio->likes_count,
            'liked_by_me' => (bool) ($portfolio->liked_by_me ?? false),
            'saved_by_me' => (bool) ($portfolio->saved_by_me ?? false),
            'created_at' => $portfolio->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Portfolio;
use App\Models\PortfolioItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación de un Portafolio (vista del dueño o pública).
 *
 * @property Portfolio $resource
 */
class PortfolioResource extends JsonResource
{
    public function __construct(Portfolio $resource, private readonly bool $public = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $portfolio = $this->resource;
        $portfolio->loadMissing(['items.media', 'serviceCategory']);

        $items = $portfolio->items->sortBy('id')->values();
        $first = $items->first();
        $category = $portfolio->serviceCategory;

        $categoryData = $category === null ? null : [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ];

        $coverThumbUrl = $first === null
            ? null
            : (new PortfolioItemResource($first, true))->resolve($request)['thumb_url'];

        $itemsData = $items
            ->map(fn (PortfolioItem $item): array => (new PortfolioItemResource($item, $this->public))->resolve($request))
            ->all();

        if ($this->public) {
            return [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'description' => $portfolio->description,
                'category' => $categoryData,
                'cover_thumb_url' => $coverThumbUrl,
                'items' => $itemsData,
            ];
        }

        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'description' => $portfolio->description,
            'category' => $categoryData,
            'uncategorized' => $category === null,
            'photos_count' => $items->count(),
            'cover_thumb_url' => $coverThumbUrl,
            'created_at' => $portfolio->created_at?->toIso8601String(),
            'items' => $itemsData,
        ];
    }
}

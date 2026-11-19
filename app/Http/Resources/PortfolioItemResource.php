<?php

namespace App\Http\Resources;

use App\Models\PortfolioItem;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación de una Foto_Portafolio.
 *
 * `image_url` siempre es el Original (valor alternativo, Req. 9.5); `thumb_url` y
 * `medium_url` solo se informan cuando la conversión WebP ya fue generada.
 *
 * @property PortfolioItem $resource
 */
class PortfolioItemResource extends JsonResource
{
    public function __construct(PortfolioItem $resource, private readonly bool $public = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $item->loadMissing('media');

        $media = $item->getFirstMedia(PortfolioService::MEDIA_COLLECTION);

        $data = [
            'id' => $item->id,
            'description' => $item->description,
            'image_url' => $media?->getUrl(),
            'thumb_url' => $media?->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
            'medium_url' => $media?->hasGeneratedConversion('medium') ? $media->getUrl('medium') : null,
        ];

        if ($this->public) {
            return $data;
        }

        return [
            'id' => $data['id'],
            'portfolio_id' => $item->portfolio_id,
            'description' => $data['description'],
            'image_url' => $data['image_url'],
            'thumb_url' => $data['thumb_url'],
            'medium_url' => $data['medium_url'],
            'conversions_ready' => $item->conversions_ready_at !== null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}

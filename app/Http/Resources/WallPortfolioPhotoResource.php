<?php

namespace App\Http\Resources;

use App\Models\PortfolioItem;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Foto_Portafolio del Muro con lista blanca. Sin indicadores de Me gusta ni Guardar:
 * esos controles pertenecen al Portafolio. Espera `media` cargada.
 *
 * @property PortfolioItem $resource
 */
class WallPortfolioPhotoResource extends JsonResource
{
    public function __construct(PortfolioItem $resource, private readonly string $fallbackAlt = '')
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $media = $item->getFirstMedia(PortfolioService::MEDIA_COLLECTION);
        $description = trim((string) $item->description);

        return [
            'id' => $item->id,
            'thumb_url' => $media?->getUrl('thumb') ?? '',
            'thumb_width' => $item->thumb_width,
            'thumb_height' => $item->thumb_height,
            'medium_url' => $media?->getUrl('medium') ?? '',
            'medium_width' => $item->medium_width,
            'medium_height' => $item->medium_height,
            'alt' => $description !== '' ? $description : $this->fallbackAlt,
        ];
    }
}

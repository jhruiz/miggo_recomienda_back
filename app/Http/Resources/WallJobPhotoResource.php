<?php

namespace App\Http\Resources;

use App\Models\JobPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Foto de resultado de un Trabajo (lista blanca). Espera `media` cargada.
 *
 * @property JobPhoto $resource
 */
class WallJobPhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $photo = $this->resource;
        $media = $photo->getFirstMedia('job_photo');

        return [
            'id' => $photo->id,
            'thumb_url' => $media?->getUrl('thumb') ?? '',
            'thumb_width' => $photo->thumb_width,
            'thumb_height' => $photo->thumb_height,
            'medium_url' => $media?->getUrl('medium') ?? '',
            'medium_width' => $photo->medium_width,
            'medium_height' => $photo->medium_height,
        ];
    }
}

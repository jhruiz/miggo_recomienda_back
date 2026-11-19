<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Detalle del Trabajo: la tarjeta más las fechas de entrega (sin montos ni descripciones).
 */
class WallJobDetailResource extends WallJobResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $job = $this->resource;

        return [
            ...$this->card(),
            'estimated_delivery_date' => $job->estimated_delivery_date?->toDateString(),
            'actual_completion_date' => $job->actual_completion_date?->toDateString(),
        ];
    }
}

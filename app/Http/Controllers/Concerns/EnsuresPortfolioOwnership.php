<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Portfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comprobación de propiedad de un Portafolio. Requiere que el controlador
 * use también HasApiResponses (para `error()`).
 */
trait EnsuresPortfolioOwnership
{
    /**
     * Retorna una respuesta 403 si el Portafolio no pertenece al Prestador
     * autenticado (o si este no tiene perfil de prestador); null si es suyo.
     */
    protected function ensureOwnership(Request $request, Portfolio $portfolio): ?JsonResponse
    {
        $profile = $request->user()?->providerProfile;

        if ($profile === null || $portfolio->provider_profile_id !== $profile->id) {
            return $this->error(
                'No tienes permiso para gestionar este portafolio.',
                status: 403,
            );
        }

        return null;
    }
}

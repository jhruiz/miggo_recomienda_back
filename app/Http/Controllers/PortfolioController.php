<?php

namespace App\Http\Controllers;

use App\Exceptions\PortfolioCountLimitReachedException;
use App\Http\Controllers\Concerns\EnsuresPortfolioOwnership;
use App\Http\Requests\StorePortfolioRequest;
use App\Http\Requests\UpdatePortfolioRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\Portfolio;
use App\Services\PortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de Portafolios del Prestador (/api/v1/profile/provider/portfolios).
 *
 * Las rutas están protegidas por `auth:sanctum` y `role.provider`. La propiedad
 * del Portafolio se verifica con EnsuresPortfolioOwnership (403).
 */
class PortfolioController extends Controller
{
    use EnsuresPortfolioOwnership;
    use HasApiResponses;

    public function __construct(private readonly PortfolioService $portfolioService)
    {
    }

    /**
     * GET /api/v1/profile/provider/portfolios
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->providerProfile;

        if ($profile === null) {
            return $this->error(
                'No tienes permiso para gestionar este portafolio.',
                status: 403,
            );
        }

        $portfolios = $this->portfolioService->listForOwner($profile);

        return $this->ok(
            data: [
                'portfolios' => $portfolios
                    ->map(fn (Portfolio $p): array => $this->portfolioService->presentForOwner($p))
                    ->values()
                    ->all(),
            ],
            message: 'Portafolios obtenidos correctamente.',
            meta: [
                'count' => $portfolios->count(),
                'max_portfolios' => PortfolioService::MAX_PORTFOLIOS_PER_PROVIDER,
                'max_photos_per_portfolio' => PortfolioService::MAX_ITEMS_PER_PORTFOLIO,
            ],
        );
    }

    /**
     * POST /api/v1/profile/provider/portfolios
     */
    public function store(StorePortfolioRequest $request): JsonResponse
    {
        $profile = $request->user()->providerProfile;

        if ($profile === null) {
            return $this->error(
                'No tienes permiso para gestionar este portafolio.',
                status: 403,
            );
        }

        try {
            $portfolio = $this->portfolioService->create($profile, $request->validated());
        } catch (PortfolioCountLimitReachedException $e) {
            return $this->error($e->getMessage(), status: 409);
        }

        return $this->created(
            data: ['portfolio' => $this->portfolioService->presentForOwner($portfolio)],
            message: 'Portafolio creado correctamente.',
        );
    }

    /**
     * GET /api/v1/profile/provider/portfolios/{portfolio}
     */
    public function show(Request $request, Portfolio $portfolio): JsonResponse
    {
        if (($denied = $this->ensureOwnership($request, $portfolio)) !== null) {
            return $denied;
        }

        return $this->ok(
            data: ['portfolio' => $this->portfolioService->presentForOwner($portfolio)],
            message: 'Portafolio obtenido correctamente.',
        );
    }

    /**
     * PUT /api/v1/profile/provider/portfolios/{portfolio}
     */
    public function update(UpdatePortfolioRequest $request, Portfolio $portfolio): JsonResponse
    {
        if (($denied = $this->ensureOwnership($request, $portfolio)) !== null) {
            return $denied;
        }

        $portfolio = $this->portfolioService->update($portfolio, $request->validated());

        return $this->ok(
            data: ['portfolio' => $this->portfolioService->presentForOwner($portfolio)],
            message: 'Portafolio actualizado correctamente.',
        );
    }

    /**
     * DELETE /api/v1/profile/provider/portfolios/{portfolio}
     */
    public function destroy(Request $request, Portfolio $portfolio): JsonResponse
    {
        if (($denied = $this->ensureOwnership($request, $portfolio)) !== null) {
            return $denied;
        }

        $deleted = $this->portfolioService->delete($portfolio);

        return $this->ok(
            data: ['deleted_items_count' => $deleted],
            message: 'Portafolio eliminado correctamente.',
        );
    }
}

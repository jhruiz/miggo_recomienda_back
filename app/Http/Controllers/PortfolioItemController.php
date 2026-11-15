<?php

namespace App\Http\Controllers;

use App\Exceptions\PortfolioPhotoLimitReachedException;
use App\Http\Controllers\Concerns\EnsuresPortfolioOwnership;
use App\Http\Requests\StorePortfolioItemRequest;
use App\Http\Requests\UpdatePortfolioItemRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Services\PortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de fotos de un Portafolio
 * (/api/v1/profile/provider/portfolios/{portfolio}/items).
 *
 * El límite de 10 fotos por Portafolio se traduce a HTTP 409. Un ítem solo se
 * opera si pertenece al Portafolio de la URL; en otro caso responde 404.
 */
class PortfolioItemController extends Controller
{
    use EnsuresPortfolioOwnership;
    use HasApiResponses;

    public function __construct(private readonly PortfolioService $portfolioService)
    {
    }

    /**
     * POST /api/v1/profile/provider/portfolios/{portfolio}/items
     */
    public function store(StorePortfolioItemRequest $request, Portfolio $portfolio): JsonResponse
    {
        if (($denied = $this->ensureOwnership($request, $portfolio)) !== null) {
            return $denied;
        }

        try {
            $item = $this->portfolioService->addItem(
                $portfolio,
                $request->file('image'),
                $request->input('description'),
            );
        } catch (PortfolioPhotoLimitReachedException $e) {
            return $this->error($e->getMessage(), status: 409);
        }

        return $this->created(
            data: ['portfolio_item' => $this->portfolioService->presentItem($item)],
            message: 'Imagen agregada al portafolio correctamente.',
        );
    }

    /**
     * PUT /api/v1/profile/provider/portfolios/{portfolio}/items/{item}
     */
    public function update(UpdatePortfolioItemRequest $request, Portfolio $portfolio, PortfolioItem $item): JsonResponse
    {
        if (($denied = $this->ensureOwnership($request, $portfolio)) !== null) {
            return $denied;
        }

        if (($missing = $this->ensureItemBelongs($portfolio, $item)) !== null) {
            return $missing;
        }

        $item = $this->portfolioService->updateItem($item, $request->validated());

        return $this->ok(
            data: ['portfolio_item' => $this->portfolioService->presentItem($item)],
            message: 'Descripción del portafolio actualizada correctamente.',
        );
    }

    /**
     * DELETE /api/v1/profile/provider/portfolios/{portfolio}/items/{item}
     */
    public function destroy(Request $request, Portfolio $portfolio, PortfolioItem $item): JsonResponse
    {
        if (($denied = $this->ensureOwnership($request, $portfolio)) !== null) {
            return $denied;
        }

        if (($missing = $this->ensureItemBelongs($portfolio, $item)) !== null) {
            return $missing;
        }

        $this->portfolioService->deleteItem($item);

        return $this->ok(message: 'Imagen eliminada del portafolio correctamente.');
    }

    /**
     * Defensa adicional a scopeBindings: 404 si el ítem no es del Portafolio.
     */
    private function ensureItemBelongs(Portfolio $portfolio, PortfolioItem $item): ?JsonResponse
    {
        if ($item->portfolio_id !== $portfolio->id) {
            return $this->error('Recurso no encontrado.', status: 404);
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\WallItemNotAvailableException;
use App\Http\Responses\HasApiResponses;
use App\Services\WallInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guardado de un Portafolio del Muro (PUT/DELETE /api/v1/wall/portfolios/{portfolio}/save).
 */
class WallPortfolioSaveController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly WallInteractionService $interactions) {}

    public function store(Request $request, int $portfolio): JsonResponse
    {
        try {
            $state = $this->interactions->savePortfolio($request->user(), $portfolio);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Portafolio guardado.');
    }

    public function destroy(Request $request, int $portfolio): JsonResponse
    {
        try {
            $state = $this->interactions->unsavePortfolio($request->user(), $portfolio);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Portafolio quitado de guardados.');
    }
}

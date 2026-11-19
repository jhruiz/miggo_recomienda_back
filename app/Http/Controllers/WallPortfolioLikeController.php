<?php

namespace App\Http\Controllers;

use App\Exceptions\WallItemNotAvailableException;
use App\Http\Responses\HasApiResponses;
use App\Services\WallInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Me gusta de un Portafolio del Muro (PUT/DELETE /api/v1/wall/portfolios/{portfolio}/like).
 */
class WallPortfolioLikeController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly WallInteractionService $interactions) {}

    public function store(Request $request, int $portfolio): JsonResponse
    {
        try {
            $state = $this->interactions->likePortfolio($request->user(), $portfolio);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Me gusta registrado.');
    }

    public function destroy(Request $request, int $portfolio): JsonResponse
    {
        try {
            $state = $this->interactions->unlikePortfolio($request->user(), $portfolio);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Me gusta eliminado.');
    }
}

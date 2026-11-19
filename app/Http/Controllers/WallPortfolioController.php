<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWallCursorException;
use App\Exceptions\WallItemNotAvailableException;
use App\Http\Controllers\Concerns\BuildsWallListingMeta;
use App\Http\Requests\IndexWallRequest;
use App\Http\Resources\WallPortfolioDetailResource;
use App\Http\Resources\WallPortfolioResource;
use App\Http\Responses\HasApiResponses;
use App\Services\WallPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pestaña Portafolios del Muro (/api/v1/wall/portfolios). Rutas bajo `auth:sanctum`.
 */
class WallPortfolioController extends Controller
{
    use BuildsWallListingMeta;
    use HasApiResponses;

    public function __construct(private readonly WallPortfolioService $wallPortfolioService) {}

    /**
     * GET /api/v1/wall/portfolios
     */
    public function index(IndexWallRequest $request): JsonResponse
    {
        try {
            $page = $this->wallPortfolioService->portfolios(
                $request->user(),
                $request->filters(),
                $request->cursor(),
            );
        } catch (InvalidWallCursorException $e) {
            return $this->error(
                $e->getMessage(),
                errors: ['cursor' => [$e->getMessage()]],
                status: 422,
            );
        }

        return $this->ok(
            data: [
                'items' => WallPortfolioResource::collection($page->items)->resolve($request),
                'next_cursor' => $page->nextCursor,
            ],
            message: 'Portafolios obtenidos correctamente.',
            meta: $this->wallListingMeta($request->user(), WallPortfolioService::PAGE_SIZE),
        );
    }

    /**
     * GET /api/v1/wall/portfolios/{portfolio}
     */
    public function show(Request $request, int $portfolio): JsonResponse
    {
        try {
            $found = $this->wallPortfolioService->detail($request->user(), $portfolio);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(
            data: (new WallPortfolioDetailResource($found))->resolve($request),
            message: 'Portafolio obtenido correctamente.',
        );
    }
}

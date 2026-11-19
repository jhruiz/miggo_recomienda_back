<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWallCursorException;
use App\Http\Requests\IndexSavedRequest;
use App\Http\Resources\WallPortfolioResource;
use App\Http\Responses\HasApiResponses;
use App\Services\WallPortfolioService;
use Illuminate\Http\JsonResponse;

/**
 * Portafolios guardados del usuario (GET /api/v1/wall/saved/portfolios). Ruta bajo `auth:sanctum`.
 */
class WallSavedPortfolioController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly WallPortfolioService $wallPortfolioService) {}

    public function index(IndexSavedRequest $request): JsonResponse
    {
        try {
            $page = $this->wallPortfolioService->saved($request->user(), $request->cursor());
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
            message: 'Guardados obtenidos correctamente.',
            meta: ['per_page' => WallPortfolioService::PAGE_SIZE],
        );
    }
}

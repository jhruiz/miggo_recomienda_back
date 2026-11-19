<?php

namespace App\Http\Controllers;

use App\Http\Requests\WallFiltersRequest;
use App\Http\Responses\HasApiResponses;
use App\Services\WallJobService;
use App\Services\WallPortfolioService;
use Illuminate\Http\JsonResponse;

/**
 * Filtros del Muro (GET /api/v1/wall/filters?tab=jobs|portfolios). `tab` es obligatorio:
 * sin él o con otro valor la petición falla con 422 y `errors.tab`.
 */
class WallFiltersController extends Controller
{
    use HasApiResponses;

    public function __construct(
        private readonly WallJobService $wallJobService,
        private readonly WallPortfolioService $wallPortfolioService,
    ) {}

    public function index(WallFiltersRequest $request): JsonResponse
    {
        return $this->ok(
            data: $request->tab() === 'jobs' ? $this->wallJobService->filters() : $this->wallPortfolioService->filters(),
            message: 'Filtros obtenidos correctamente.',
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWallCursorException;
use App\Exceptions\WallItemNotAvailableException;
use App\Http\Controllers\Concerns\BuildsWallListingMeta;
use App\Http\Requests\IndexWallRequest;
use App\Http\Resources\WallJobDetailResource;
use App\Http\Resources\WallJobResource;
use App\Http\Responses\HasApiResponses;
use App\Services\WallJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pestaña Trabajos del Muro (/api/v1/wall/jobs). Rutas bajo `auth:sanctum`.
 */
class WallJobController extends Controller
{
    use BuildsWallListingMeta;
    use HasApiResponses;

    public function __construct(private readonly WallJobService $wallJobService) {}

    /**
     * GET /api/v1/wall/jobs
     */
    public function index(IndexWallRequest $request): JsonResponse
    {
        try {
            $page = $this->wallJobService->jobs(
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
                'items' => WallJobResource::collection($page->items)->resolve($request),
                'next_cursor' => $page->nextCursor,
            ],
            message: 'Trabajos obtenidos correctamente.',
            meta: $this->wallListingMeta($request->user(), WallJobService::PAGE_SIZE),
        );
    }

    /**
     * GET /api/v1/wall/jobs/{job}
     */
    public function show(Request $request, int $job): JsonResponse
    {
        try {
            $found = $this->wallJobService->detail($request->user(), $job);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(
            data: (new WallJobDetailResource($found))->resolve($request),
            message: 'Trabajo obtenido correctamente.',
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWallCursorException;
use App\Http\Requests\IndexSavedRequest;
use App\Http\Resources\WallJobResource;
use App\Http\Responses\HasApiResponses;
use App\Services\WallJobService;
use Illuminate\Http\JsonResponse;

/**
 * Trabajos guardados del usuario (GET /api/v1/wall/saved/jobs). Ruta bajo `auth:sanctum`.
 */
class WallSavedJobController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly WallJobService $wallJobService) {}

    public function index(IndexSavedRequest $request): JsonResponse
    {
        try {
            $page = $this->wallJobService->saved($request->user(), $request->cursor());
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
            message: 'Guardados obtenidos correctamente.',
            meta: ['per_page' => WallJobService::PAGE_SIZE],
        );
    }
}

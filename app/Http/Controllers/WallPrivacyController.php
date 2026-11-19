<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWallCursorException;
use App\Http\Requests\IndexWallEventsRequest;
use App\Http\Requests\UpdateWallPrivacyRequest;
use App\Http\Resources\FeedEventResource;
use App\Http\Resources\FeedPreferenceResource;
use App\Http\Responses\HasApiResponses;
use App\Services\FeedPrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Privacidad del Muro (/api/v1/wall/privacy). Rutas bajo `auth:sanctum`.
 *
 * Todas operan sobre el usuario autenticado; ninguna recibe un id de usuario.
 */
class WallPrivacyController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly FeedPrivacyService $privacyService) {}

    /**
     * GET /api/v1/wall/privacy
     */
    public function show(Request $request): JsonResponse
    {
        return $this->ok(
            data: (new FeedPreferenceResource($this->privacyService->summary($request->user())))->resolve($request),
            message: 'Privacidad obtenida correctamente.',
        );
    }

    /**
     * PUT /api/v1/wall/privacy
     */
    public function update(UpdateWallPrivacyRequest $request): JsonResponse
    {
        $summary = $this->privacyService->setBehaviorLogging(
            $request->user(),
            $request->boolean('behavior_logging'),
        );

        return $this->ok(
            data: (new FeedPreferenceResource($summary))->resolve($request),
            message: 'Privacidad actualizada correctamente.',
        );
    }

    /**
     * GET /api/v1/wall/privacy/events
     */
    public function events(IndexWallEventsRequest $request): JsonResponse
    {
        try {
            $page = $this->privacyService->history(
                $request->user(),
                $request->cursor(),
                $request->perPage(),
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
                'events' => FeedEventResource::collection($page->events)->resolve($request),
                'next_cursor' => $page->nextCursor,
            ],
            message: 'Historial obtenido correctamente.',
            meta: ['per_page' => $request->perPage()],
        );
    }

    /**
     * DELETE /api/v1/wall/privacy/events
     */
    public function destroyEvents(Request $request): JsonResponse
    {
        return $this->ok(
            data: ['deleted_count' => $this->privacyService->reset($request->user())],
            message: 'Historial eliminado correctamente.',
        );
    }
}

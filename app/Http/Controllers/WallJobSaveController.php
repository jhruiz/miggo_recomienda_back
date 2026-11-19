<?php

namespace App\Http\Controllers;

use App\Exceptions\WallItemNotAvailableException;
use App\Http\Responses\HasApiResponses;
use App\Services\WallInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guardado de un Trabajo del Muro (PUT/DELETE /api/v1/wall/jobs/{job}/save).
 */
class WallJobSaveController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly WallInteractionService $interactions) {}

    public function store(Request $request, int $job): JsonResponse
    {
        try {
            $state = $this->interactions->saveJob($request->user(), $job);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Trabajo guardado.');
    }

    public function destroy(Request $request, int $job): JsonResponse
    {
        try {
            $state = $this->interactions->unsaveJob($request->user(), $job);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Trabajo quitado de guardados.');
    }
}

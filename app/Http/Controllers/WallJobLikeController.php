<?php

namespace App\Http\Controllers;

use App\Exceptions\WallItemNotAvailableException;
use App\Http\Responses\HasApiResponses;
use App\Services\WallInteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Me gusta de un Trabajo del Muro (PUT/DELETE /api/v1/wall/jobs/{job}/like).
 */
class WallJobLikeController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly WallInteractionService $interactions) {}

    public function store(Request $request, int $job): JsonResponse
    {
        try {
            $state = $this->interactions->likeJob($request->user(), $job);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Me gusta registrado.');
    }

    public function destroy(Request $request, int $job): JsonResponse
    {
        try {
            $state = $this->interactions->unlikeJob($request->user(), $job);
        } catch (WallItemNotAvailableException $e) {
            return $this->error($e->getMessage(), status: 404);
        }

        return $this->ok(data: $state->toArray(), message: 'Me gusta eliminado.');
    }
}

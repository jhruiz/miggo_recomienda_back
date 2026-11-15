<?php

namespace App\Http\Controllers;

use App\Exceptions\RatingSubmissionException;
use App\Http\Requests\StoreRatingRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\Job;
use App\Models\Rating;
use App\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de Calificaciones (/api/v1/ratings).
 *
 * Tarea 12.1 (Requisitos 8.1, 8.2, 8.3, 8.4, 8.6, 8.7):
 *   - POST /ratings            Emitir una Calificación para un Trabajo.
 *   - GET  /ratings/job/{job}  Listar las Calificaciones de un Trabajo.
 *
 * Ambos endpoints requieren autenticación (auth:sanctum). La emisión asocia la
 * Calificación al Trabajo y al par (calificador, calificado) correcto; el
 * listado solo es accesible a las partes del Trabajo (403 en otro caso).
 */
class RatingController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly RatingService $service)
    {
    }

    /**
     * POST /api/v1/ratings  (auth:sanctum)
     *
     * Emite la Calificación del Usuario autenticado sobre su contraparte en el
     * Trabajo (Requisitos 8.2, 8.4). La validación de score/comentario la hace
     * StoreRatingRequest (422 sin persistir, Requisito 8.3).
     *
     * Errores de negocio (RatingSubmissionException → status):
     *  - 409 si las Calificaciones no están habilitadas (Trabajo no completado).
     *  - 403 si el Usuario no es parte del Trabajo.
     *  - 409 si ya emitió su Calificación (duplicada, Requisito 8.6).
     *  - 409 si la ventana de 30 días ya se cerró (Requisito 8.7).
     */
    public function store(StoreRatingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $job = Job::findOrFail($data['job_id']);

        try {
            $rating = $this->service->submitRating(
                $request->user(),
                $job,
                (int) $data['score'],
                $data['comment'],
            );
        } catch (RatingSubmissionException $e) {
            return $this->error($e->getMessage(), status: $e->status());
        }

        return $this->created(
            data: ['rating' => $this->present($rating)],
            message: 'Calificación registrada correctamente.',
        );
    }

    /**
     * GET /api/v1/ratings/job/{job}  (auth:sanctum — partes del Trabajo)
     *
     * Lista las Calificaciones emitidas de un Trabajo. Solo las partes
     * involucradas (Cliente o Prestador) pueden consultarlas; 403 en otro caso.
     */
    public function forJob(Request $request, Job $job): JsonResponse
    {
        $userId = $request->user()->id;

        if (! in_array($userId, [$job->client_user_id, $job->provider_user_id], true)) {
            return $this->error(
                'No tienes permiso para ver las calificaciones de este trabajo.',
                status: 403,
            );
        }

        $ratings = $this->service->ratingsForJob($job)
            ->map(fn (Rating $rating) => $this->present($rating))
            ->all();

        return $this->ok(
            data: ['ratings' => $ratings],
            message: 'Listado de calificaciones del trabajo.',
        );
    }

    /**
     * Representación de una Calificación para la API.
     *
     * @return array<string, mixed>
     */
    private function present(Rating $rating): array
    {
        return [
            'id' => $rating->id,
            'job_id' => $rating->job_id,
            'rater_user_id' => $rating->rater_user_id,
            'rated_user_id' => $rating->rated_user_id,
            'score' => $rating->score,
            'comment' => $rating->comment,
            'is_visible' => $rating->is_visible,
            'rated_at' => $rating->rated_at?->toIso8601String(),
            'window_closes_at' => $rating->window_closes_at?->toIso8601String(),
        ];
    }
}

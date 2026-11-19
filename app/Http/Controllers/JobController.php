<?php

namespace App\Http\Controllers;

use App\Exceptions\JobPhotoLimitExceededException;
use App\Exceptions\JobTransitionException;
use App\Http\Requests\CancelJobRequest;
use App\Http\Requests\CompleteJobRequest;
use App\Http\Requests\RejectJobCompletionRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\Job;
use App\Services\JobPhotoService;
use App\Services\JobService;
use App\Services\WallJobService;
use App\Support\AvatarPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de Trabajos (/api/v1/jobs).
 *
 * Tarea 9.1 (Requisitos 7.1, 7.2):
 *   - GET /jobs       Lista los Trabajos del usuario autenticado (como Cliente
 *                     o como Prestador).
 *   - GET /jobs/{id}  Detalle de un Trabajo. Solo las partes involucradas
 *                     (Cliente o Prestador) pueden verlo (403 en otro caso).
 *
 * Tarea 9.2 (Requisitos 7.3, 7.4, 7.5, 7.6):
 *   - POST /jobs/{id}/complete            El Prestador marca el Trabajo como
 *                                         completado (403 si no es el Prestador;
 *                                         409 si no está en progreso).
 *   - POST /jobs/{id}/confirm             El Cliente confirma la finalización
 *                                         (403 si no es el Cliente; 409 si no
 *                                         está a la espera de confirmación).
 *   - POST /jobs/{id}/reject-completion   El Cliente rechaza la finalización con
 *                                         un motivo ≤500 (403/409/422).
 *
 * Todas las rutas exigen auth:sanctum. La creación del Trabajo no se expone como
 * endpoint: ocurre al aceptar una Cotización vía QuoteService (tarea 8.5), que
 * invoca JobService::createJob().
 */
class JobController extends Controller
{
    use HasApiResponses;

    public function __construct(
        private readonly JobService $service,
        private readonly JobPhotoService $photoService,
    ) {}

    /**
     * GET /api/v1/jobs  (auth:sanctum)
     *
     * Lista los Trabajos en los que el usuario autenticado participa.
     */
    public function index(Request $request): JsonResponse
    {
        $jobs = $this->service->jobsForUser($request->user())
            ->map(fn (Job $job): array => $this->present($job))
            ->all();

        $message = $jobs === []
            ? 'No tienes trabajos todavía.'
            : 'Listado de trabajos.';

        return $this->ok(
            data: ['jobs' => $jobs],
            message: $message,
        );
    }

    /**
     * GET /api/v1/jobs/pending-rating  (auth:sanctum, throttle:wall-read)
     *
     * Trabajos completados del usuario como Cliente con su calificación aún
     * abierta (misma regla que can_rate_by_me y RatingService::submitRating).
     */
    public function pendingRating(Request $request, WallJobService $wallJobs): JsonResponse
    {
        return $this->ok(
            data: $wallJobs->pendingRating($request->user()),
            message: 'Trabajos por calificar.',
        );
    }

    /**
     * GET /api/v1/jobs/{job}  (auth:sanctum — partes involucradas)
     *
     * Detalle de un Trabajo. Devuelve 403 si el solicitante no es ni el Cliente
     * ni el Prestador del Trabajo.
     */
    public function show(Request $request, Job $job): JsonResponse
    {
        if (! $this->service->userParticipates($job, $request->user())) {
            return $this->error(
                'No tienes permiso para ver este trabajo.',
                status: 403,
            );
        }

        return $this->ok(
            data: ['job' => $this->present($job)],
            message: 'Detalle del trabajo.',
        );
    }

    /**
     * POST /api/v1/jobs/{job}/complete  (auth:sanctum — Prestador del Trabajo)
     *
     * El Prestador marca el Trabajo como completado (Requisito 7.3): registra la
     * fecha real, notifica al Cliente para confirmar y despacha el
     * auto-completado a 7 días. 403 si no es el Prestador; 409 si no procede.
     */
    public function complete(CompleteJobRequest $request, Job $job): JsonResponse
    {
        if ($job->provider_user_id !== $request->user()->id) {
            return $this->error(
                'Solo el prestador del trabajo puede marcarlo como completado.',
                status: 403,
            );
        }

        try {
            $job = $this->service->markAsCompleted($job, $request->file('photos') ?? []);
        } catch (JobTransitionException|JobPhotoLimitExceededException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: ['job' => $this->present($job)],
            message: 'Trabajo marcado como completado. Se notificó al cliente para que confirme.',
        );
    }

    /**
     * POST /api/v1/jobs/{job}/confirm  (auth:sanctum — Cliente del Trabajo)
     *
     * El Cliente confirma la finalización (Requisito 7.4): el estado pasa a
     * "completado", se habilitan las Calificaciones y se recalcula el
     * Índice_Cumplimiento (vía seam). 403 si no es el Cliente; 409 si no procede.
     */
    public function confirm(Request $request, Job $job): JsonResponse
    {
        if ($job->client_user_id !== $request->user()->id) {
            return $this->error(
                'Solo el cliente del trabajo puede confirmar su finalización.',
                status: 403,
            );
        }

        try {
            $job = $this->service->confirmCompletion($job);
        } catch (JobTransitionException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: ['job' => $this->present($job)],
            message: 'Finalización del trabajo confirmada.',
        );
    }

    /**
     * POST /api/v1/jobs/{job}/reject-completion  (auth:sanctum — Cliente del Trabajo)
     *
     * El Cliente rechaza la finalización (Requisito 7.6): el Trabajo se mantiene
     * "en progreso", se guarda el motivo (≤500, validado en el Form Request) y
     * se notifica al Prestador. 403 si no es el Cliente; 409 si no procede; 422
     * si el motivo es inválido.
     */
    public function rejectCompletion(RejectJobCompletionRequest $request, Job $job): JsonResponse
    {
        if ($job->client_user_id !== $request->user()->id) {
            return $this->error(
                'Solo el cliente del trabajo puede rechazar su finalización.',
                status: 403,
            );
        }

        try {
            $job = $this->service->rejectCompletion($job, $request->validated()['reason']);
        } catch (JobTransitionException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: ['job' => $this->present($job)],
            message: 'Finalización del trabajo rechazada. Se notificó al prestador.',
        );
    }

    /**
     * POST /api/v1/jobs/{job}/request-cancel  (auth:sanctum — partes del Trabajo)
     *
     * Una parte (Cliente o Prestador) solicita cancelar el Trabajo de mutuo
     * acuerdo (Requisito 7.8): marca su flag, guarda su motivo (≤500), mantiene
     * el estado "en progreso" y notifica a la otra parte. Si la contraparte ya
     * había solicitado, el Trabajo pasa a "cancelado" (Requisito 7.7). 403 si no
     * es una de las partes; 409 si no está en progreso; 422 si el motivo es
     * inválido.
     */
    public function requestCancel(CancelJobRequest $request, Job $job): JsonResponse
    {
        $user = $request->user();

        if (! $this->service->userParticipates($job, $user)) {
            return $this->error(
                'No tienes permiso para cancelar este trabajo.',
                status: 403,
            );
        }

        try {
            $job = $this->service->requestCancellation($job, $user, $request->validated()['reason']);
        } catch (JobTransitionException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        $message = $job->status === Job::STATUS_CANCELADO
            ? 'Trabajo cancelado de mutuo acuerdo.'
            : 'Solicitud de cancelación registrada. Se notificó a la otra parte.';

        return $this->ok(
            data: ['job' => $this->present($job)],
            message: $message,
        );
    }

    /**
     * POST /api/v1/jobs/{job}/confirm-cancel  (auth:sanctum — partes del Trabajo)
     *
     * La otra parte confirma la cancelación de mutuo acuerdo (Requisito 7.7):
     * registra su flag y motivo (≤500). Cuando ambas partes han solicitado, el
     * estado pasa a "cancelado" y se conservan ambos motivos. 403 si no es una
     * de las partes; 409 si no está en progreso; 422 si el motivo es inválido.
     */
    public function confirmCancel(CancelJobRequest $request, Job $job): JsonResponse
    {
        $user = $request->user();

        if (! $this->service->userParticipates($job, $user)) {
            return $this->error(
                'No tienes permiso para cancelar este trabajo.',
                status: 403,
            );
        }

        try {
            $job = $this->service->confirmCancellation($job, $user, $request->validated()['reason']);
        } catch (JobTransitionException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        $message = $job->status === Job::STATUS_CANCELADO
            ? 'Trabajo cancelado de mutuo acuerdo.'
            : 'Solicitud de cancelación registrada. Se notificó a la otra parte.';

        return $this->ok(
            data: ['job' => $this->present($job)],
            message: $message,
        );
    }

    /**
     * Representación de un Trabajo para la API.
     *
     * @return array<string, mixed>
     */
    private function present(Job $job): array
    {
        $job->loadMissing(['client:id,name', 'client.media', 'provider:id,name', 'provider.media']);

        $presented = [
            'id' => $job->id,
            'quote_id' => $job->quote_id,
            'client_user_id' => $job->client_user_id,
            'client_name' => $job->client?->name,
            'provider_user_id' => $job->provider_user_id,
            'provider_name' => $job->provider?->name,
            ...AvatarPresenter::thumb($job->client, 'client_'),
            ...AvatarPresenter::thumb($job->provider, 'provider_'),
            'status' => $job->status,
            'start_date' => $job->start_date?->toDateString(),
            'estimated_delivery_date' => $job->estimated_delivery_date?->toDateString(),
            'actual_completion_date' => $job->actual_completion_date?->toDateString(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'rejection_reason' => $job->rejection_reason,
            'client_requested_cancel' => (bool) $job->client_requested_cancel,
            'provider_requested_cancel' => (bool) $job->provider_requested_cancel,
            'cancellation_reason' => $job->cancellation_reason,
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];

        // Las Fotos_Trabajo (con estado de conversiones) solo las ve el Prestador.
        $viewerId = auth()->id();

        if ($viewerId !== null && (int) $job->provider_user_id === (int) $viewerId) {
            $presented['photos'] = $this->photoService->presentOwner($job);
        }

        return $presented;
    }
}

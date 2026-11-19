<?php

namespace App\Services;

use App\Contracts\JobCompletionHandler;
use App\Contracts\JobNotifier;
use App\Exceptions\JobPhotoLimitExceededException;
use App\Exceptions\JobTransitionException;
use App\Jobs\AutoCompleteJobJob;
use App\Models\Job;
use App\Models\ProviderProfile;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de Trabajos (Gestor_Trabajos del dominio).
 *
 * Cubre la tarea 9.1: creación de un Trabajo al aceptar una Cotización y
 * cálculo automático de sus fechas (Requisitos 7.1, 7.2), más el listado y el
 * detalle de los Trabajos del usuario autenticado.
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 9.1 / tareas 8.5, 13.1)
 * -----------------------------------------------------------------------------
 * - createJob() se expone como método público para que QuoteService::acceptQuote
 *   (tarea 8.5) lo invoque dentro de su transacción al aceptar una Cotización
 *   (Requisito 5.7 / 7.1). Es idempotente respecto a la relación 1:1
 *   Cotización→Trabajo: si el Trabajo ya existe para la Cotización, lo devuelve
 *   en lugar de duplicarlo.
 * - La creación del canal de Chat "activo" asociado al Trabajo (Requisito 11.1)
 *   la realiza la tarea 13.1 (ChatService) enganchada de forma aditiva dentro
 *   de createJob(): al crear el Trabajo se invoca
 *   ChatService::createActiveChannelForJob(), que es idempotente (no duplica el
 *   canal si ya existe).
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 9.2 / tareas 14.1, 12.1, 9.6)
 * -----------------------------------------------------------------------------
 * El flujo de completar / confirmar / rechazar finalización (Requisitos 7.3,
 * 7.4, 7.5, 7.6) usa dos seams para no acoplarse a componentes aún
 * inexistentes:
 *   - JobNotifier: notificaciones (hoy LogJobNotifier; real en tarea 14.1).
 *   - JobCompletionHandler: habilitar Calificaciones (tarea 12.1) y recalcular
 *     el Índice_Cumplimiento (tarea 9.6) al pasar un Trabajo a "completado".
 *     Hoy es un no-op (NullJobCompletionHandler).
 *
 * MODELADO DEL ESTADO "a la espera de confirmación": el Trabajo mantiene el
 * estado "en_progreso" tras marcarlo el Prestador como completado (Requisito
 * 7.3). Para distinguir ese sub-estado se usa `actual_completion_date`: una vez
 * fijada, el Trabajo está a la espera de que el Cliente confirme (7.4) o rechace
 * (7.6). Rechazar limpia esa fecha y devuelve el Trabajo a "en_progreso" puro.
 * -----------------------------------------------------------------------------
 */
class JobService
{
    public function __construct(
        private readonly JobNotifier $notifier,
        private readonly JobCompletionHandler $completionHandler,
        private readonly ChatService $chatService,
        private readonly JobPhotoService $photoService,
    ) {}

    /**
     * Crea el Trabajo originado al aceptar una Cotización (Requisitos 7.1, 7.2).
     *
     * Reglas aplicadas:
     *  - `start_date` = fecha de aceptación de la Cotización (hoy), a nivel de día.
     *  - `estimated_delivery_date` = `start_date` + `delivery_days` de la
     *    Cotización, sumados como días calendario (Requisito 7.1).
     *  - Estado inicial "en_progreso" (Requisito 7.2).
     *  - Las partes (Cliente y Prestador) se derivan de la propia Cotización,
     *    nunca de datos externos.
     *
     * La relación Cotización→Trabajo es 1:1. Para ser seguro ante reintentos y
     * ante la transacción de aceptación de QuoteService, si ya existe un Trabajo
     * para la Cotización se devuelve el existente sin crear uno nuevo.
     */
    public function createJob(Quote $quote): Job
    {
        $existing = $quote->job()->first();

        if ($existing !== null) {
            return $existing;
        }

        $startDate = Carbon::today();
        $estimatedDeliveryDate = $startDate->copy()->addDays((int) $quote->delivery_days);

        return DB::transaction(function () use ($quote, $startDate, $estimatedDeliveryDate): Job {
            $job = Job::create([
                'quote_id' => $quote->id,
                'client_user_id' => $quote->client_user_id,
                'provider_user_id' => $quote->provider_user_id,
                'service_category_id' => $quote->service_category_id
                    ?? $quote->quoteRequest?->service_category_id,
                'status' => Job::STATUS_EN_PROGRESO,
                'start_date' => $startDate,
                'estimated_delivery_date' => $estimatedDeliveryDate,
            ]);

            // Habilita el canal de Chat "activo" entre Cliente y Prestador del
            // Trabajo recién creado (Requisito 11.1). Enganche aditivo e
            // idempotente a través del Gestor_Chat (tarea 13.1): si el Trabajo
            // ya tuviera canal, no se duplica.
            $this->chatService->createActiveChannelForJob($job);

            return $job;
        });
    }

    /**
     * Lista los Trabajos en los que el usuario participa, ya sea como Cliente o
     * como Prestador, ordenados del más reciente al más antiguo.
     *
     * @return Collection<int, Job>
     */
    public function jobsForUser(User $user): Collection
    {
        return Job::query()
            ->with(['quote:id,service_description,total_price,delivery_days', 'client:id,name', 'client.media', 'provider:id,name', 'provider.media'])
            ->where(function ($query) use ($user): void {
                $query->where('client_user_id', $user->id)
                    ->orWhere('provider_user_id', $user->id);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Indica si el usuario es una de las partes (Cliente o Prestador) del Trabajo.
     */
    public function userParticipates(Job $job, User $user): bool
    {
        return $job->client_user_id === $user->id
            || $job->provider_user_id === $user->id;
    }

    /**
     * El Prestador marca el Trabajo como completado (Requisito 7.3).
     *
     * Reglas de negocio:
     *  - Solo el Prestador del Trabajo puede ejecutarla; el control de acceso
     *    (403) lo aplica el controlador antes de invocar este método.
     *  - Solo es válida mientras el Trabajo está "en_progreso" y aún no se ha
     *    marcado una fecha de finalización real; en otro caso se lanza
     *    JobTransitionException (HTTP 409).
     *
     * Efectos:
     *  - Registra `actual_completion_date` = hoy (fecha de finalización real).
     *  - El estado SE MANTIENE en "en_progreso": la finalización queda a la
     *    espera de confirmación del Cliente (Requisito 7.3).
     *  - Notifica al Cliente para que confirme o rechace (Requisito 7.3).
     *  - Despacha AutoCompleteJobJob con delay de 7 días calendario: si el
     *    Cliente no responde, el Trabajo se completará automáticamente
     *    (Requisito 7.5).
     *
     * @param  list<UploadedFile>  $photos  Fotos_Trabajo opcionales (0 a 5).
     *
     * @throws JobTransitionException si el Trabajo no admite ser completado.
     * @throws JobPhotoLimitExceededException si las fotos superan el máximo.
     */
    public function markAsCompleted(Job $job, array $photos = []): Job
    {
        if (! $this->isAwaitingProviderCompletion($job)) {
            throw JobTransitionException::notInProgressForCompletion();
        }

        // Las fotos (0 a 5) se guardan en la misma transacción que la fecha de
        // cierre: si fallan, el Trabajo no se cierra y no se notifica.
        $job = DB::transaction(function () use ($job, $photos): Job {
            $job->actual_completion_date = Carbon::today();
            $job->save();

            if ($photos !== []) {
                $this->photoService->addPhotos($job, $photos);
            }

            return $job->refresh();
        });

        // Notifica al Cliente para que confirme o rechace (Requisito 7.3).
        $this->notifier->notifyClientOfCompletion($job);

        // Auto-completado si el Cliente no responde en 7 días (Requisito 7.5).
        AutoCompleteJobJob::dispatch($job->id)->delay(now()->addDays(7));

        return $job;
    }

    /**
     * El Cliente confirma la finalización del Trabajo (Requisito 7.4).
     *
     * Reglas de negocio:
     *  - Solo el Cliente del Trabajo puede ejecutarla; el control de acceso
     *    (403) lo aplica el controlador.
     *  - Solo es válida cuando el Trabajo está "en_progreso" y el Prestador ya
     *    marcó la finalización (`actual_completion_date` fijada); en otro caso
     *    se lanza JobTransitionException (HTTP 409).
     *
     * Efectos:
     *  - El estado pasa a "completado".
     *  - Se invoca el seam de finalización (habilitar Calificaciones + recalcular
     *    Índice_Cumplimiento, tareas 12.1/9.6).
     *  - El AutoCompleteJobJob pendiente queda efectivamente cancelado: al no
     *    estar ya "en_progreso", su handle() será un no-op (misma guarda que
     *    ExpireQuoteJob).
     *
     * @throws JobTransitionException si el Trabajo no está a la espera de confirmación.
     */
    public function confirmCompletion(Job $job): Job
    {
        if (! $this->isAwaitingClientDecision($job)) {
            throw JobTransitionException::notAwaitingConfirmation();
        }

        $job = DB::transaction(function () use ($job): Job {
            $job->status = Job::STATUS_COMPLETADO;
            $job->save();

            return $job->refresh();
        });

        // Habilita Calificaciones y recalcula Índice_Cumplimiento (Req 7.4).
        $this->completionHandler->onCompleted($job);

        return $job;
    }

    /**
     * El Cliente rechaza la finalización del Trabajo (Requisito 7.6).
     *
     * Reglas de negocio:
     *  - Solo el Cliente del Trabajo puede ejecutarla; el control de acceso
     *    (403) lo aplica el controlador.
     *  - Solo es válida cuando el Trabajo está "en_progreso" y el Prestador ya
     *    marcó la finalización; en otro caso se lanza JobTransitionException
     *    (HTTP 409).
     *  - El motivo (`$reason`, ≤500) ya viene validado por el Form Request.
     *
     * Efectos:
     *  - El estado SE MANTIENE en "en_progreso" (Requisito 7.6).
     *  - Se limpia `actual_completion_date`: el Trabajo vuelve a estar en curso
     *    y el Prestador podrá volver a marcarlo como completado más adelante.
     *  - Se guarda `rejection_reason`.
     *  - Se notifica al Prestador incluyendo el motivo (Requisito 7.6).
     *  - El AutoCompleteJobJob despachado antes del rechazo queda neutralizado:
     *    su handle() exige `actual_completion_date` no nula, que aquí se limpia,
     *    por lo que será un no-op. Si el Prestador vuelve a marcar la
     *    finalización, markAsCompleted despacha un nuevo AutoCompleteJobJob.
     *
     * @throws JobTransitionException si el Trabajo no está a la espera de confirmación.
     */
    public function rejectCompletion(Job $job, string $reason): Job
    {
        if (! $this->isAwaitingClientDecision($job)) {
            throw JobTransitionException::notAwaitingConfirmation();
        }

        $job = DB::transaction(function () use ($job, $reason): Job {
            // El estado se mantiene "en_progreso" (Requisito 7.6). Se limpia la
            // fecha de finalización para que el AutoCompleteJobJob pendiente no
            // complete el Trabajo (su handle() exige esa fecha no nula).
            $job->status = Job::STATUS_EN_PROGRESO;
            $job->actual_completion_date = null;
            $job->rejection_reason = $reason;
            $job->save();

            return $job->refresh();
        });

        // Notifica al Prestador con el motivo del rechazo (Requisito 7.6).
        $this->notifier->notifyProviderOfRejection($job, $reason);

        return $job;
    }

    /**
     * Una parte (Cliente o Prestador) solicita cancelar el Trabajo de mutuo
     * acuerdo (Requisitos 7.7, 7.8).
     *
     * Reglas de negocio:
     *  - Solo las partes del Trabajo pueden solicitarla; el control de acceso
     *    (403) lo aplica el controlador antes de invocar este método.
     *  - Solo es válida mientras el Trabajo está "en_progreso"; en otro caso se
     *    lanza JobTransitionException (HTTP 409).
     *  - El motivo (`$reason`, ≤500) ya viene validado por el Form Request.
     *
     * Efectos (una sola parte ha solicitado):
     *  - Marca el flag de la parte solicitante (`client_requested_cancel` o
     *    `provider_requested_cancel`) y guarda su motivo bajo la clave
     *    correspondiente de `cancellation_reason` (objeto client/provider).
     *  - El estado SE MANTIENE "en_progreso" (Requisito 7.8).
     *  - Notifica a la OTRA parte de la solicitud pendiente (Requisito 7.8).
     *
     * Efectos (la contraparte ya había solicitado antes):
     *  - Al quedar ambos flags en true, el Trabajo se cancela: estado
     *    "cancelado" y ambos motivos conservados (Requisito 7.7). Equivale a
     *    confirmar la cancelación, de modo que este endpoint también cierra el
     *    flujo cuando lo invoca la segunda parte.
     *
     * @throws JobTransitionException si el Trabajo no está "en_progreso".
     */
    public function requestCancellation(Job $job, User $actor, string $reason): Job
    {
        if ($job->status !== Job::STATUS_EN_PROGRESO) {
            throw JobTransitionException::notInProgressForCancellation();
        }

        $isClient = $job->client_user_id === $actor->id;
        $recipientUserId = $isClient ? $job->provider_user_id : $job->client_user_id;

        $job = DB::transaction(function () use ($job, $isClient, $reason): Job {
            $reasons = $job->cancellation_reason ?? [];
            $reasons[$isClient ? 'client' : 'provider'] = $reason;

            if ($isClient) {
                $job->client_requested_cancel = true;
            } else {
                $job->provider_requested_cancel = true;
            }

            $job->cancellation_reason = $reasons;

            // Si ambas partes ya han solicitado la cancelación, el Trabajo pasa
            // a "cancelado" (Requisito 7.7); si no, se mantiene (Requisito 7.8).
            if ($job->client_requested_cancel && $job->provider_requested_cancel) {
                $job->status = Job::STATUS_CANCELADO;
            }

            $job->save();

            return $job->refresh();
        });

        if ($job->status === Job::STATUS_CANCELADO) {
            // La contraparte ya había solicitado: la cancelación mutua se
            // completa en esta misma llamada (Requisito 7.7).
            $this->notifier->notifyJobCancelled($job, $recipientUserId);
        } else {
            // Solo una parte ha solicitado: se notifica la solicitud pendiente
            // a la contraparte (Requisito 7.8).
            $this->notifier->notifyCancellationRequested($job, $recipientUserId, $reason);
        }

        return $job;
    }

    /**
     * La otra parte confirma la cancelación de mutuo acuerdo (Requisito 7.7).
     *
     * Reglas de negocio:
     *  - Solo las partes del Trabajo pueden confirmarla; el control de acceso
     *    (403) lo aplica el controlador.
     *  - Solo es válida mientras el Trabajo está "en_progreso"; en otro caso se
     *    lanza JobTransitionException (HTTP 409).
     *  - El motivo (`$reason`, ≤500) ya viene validado por el Form Request.
     *
     * Semánticamente idéntica a `requestCancellation`: registra el flag y el
     * motivo de la parte que confirma y, cuando ambos flags quedan en true, el
     * estado pasa a "cancelado" conservando ambos motivos. Se expone como
     * endpoint distinto (`confirm-cancel`) para que la segunda parte complete el
     * acuerdo con una semántica explícita.
     *
     * @throws JobTransitionException si el Trabajo no está "en_progreso".
     */
    public function confirmCancellation(Job $job, User $actor, string $reason): Job
    {
        return $this->requestCancellation($job, $actor, $reason);
    }

    /**
     * Recalcula y cachea el Índice_Cumplimiento del Prestador (Requisitos 9.1,
     * 9.3, 9.5, 9.6).
     *
     * Se recorren los Trabajos del Prestador en estado "completado" y se
     * actualizan dos campos cacheados en su `provider_profile`:
     *
     *  - `completed_jobs_count`: número TOTAL de Trabajos completados del
     *    Prestador. Incluye los que no tienen fecha de entrega estimada, porque
     *    el Requisito 9.6 exige excluir esos Trabajos del cálculo del índice
     *    SIN afectar el conteo de Trabajos completados mostrado en el Perfil.
     *
     *  - `compliance_index`: porcentaje ENTERO (0-100) de Trabajos completados
     *    cuya `actual_completion_date <= estimated_delivery_date`, calculado
     *    ÚNICAMENTE sobre los Trabajos completados que tienen fecha de entrega
     *    estimada registrada (Requisito 9.1). Los Trabajos sin fecha estimada
     *    se excluyen del ratio (Requisito 9.6).
     *
     *    Si ningún Trabajo completado tiene fecha estimada, el denominador es 0
     *    y no se puede calcular un porcentaje: se cachea `null`, que la capa de
     *    presentación muestra como "Sin datos suficientes" en lugar de 0%
     *    (Requisito 9.5). La regla de "<3 trabajos completados" (Requisito 9.4)
     *    la aplica el presentador (ProfileService::presentComplianceIndex)
     *    sobre estos valores cacheados; aquí solo se recalculan y guardan.
     *
     * El Prestador puede indicarse como instancia de User, como su id o como su
     * ProviderProfile. Si el Prestador aún no tiene un `provider_profile`, no
     * hay nada que cachear y el método es un no-op (retorna null).
     *
     * Es idempotente: puede invocarse las veces que haga falta y siempre deja
     * el perfil en el estado consistente con los Trabajos completados actuales.
     * Se invoca al pasar un Trabajo a "completado" (Requisito 9.3) a través del
     * seam JobCompletionHandler, y también es directamente invocable de forma
     * autónoma (p. ej. para recomputar en lote o en pruebas).
     *
     * @param  User|ProviderProfile|int  $provider  Prestador, su perfil o su id de usuario.
     * @return int|null El `compliance_index` recalculado, o null si no procede.
     */
    public function recalculateComplianceIndex(User|ProviderProfile|int $provider): ?int
    {
        $providerUserId = match (true) {
            $provider instanceof User => $provider->id,
            $provider instanceof ProviderProfile => $provider->user_id,
            default => $provider,
        };

        $profile = ProviderProfile::query()
            ->where('user_id', $providerUserId)
            ->first();

        // Sin perfil de Prestador no hay dónde cachear el índice (Req 9.3).
        if ($profile === null) {
            return null;
        }

        $completedQuery = Job::query()
            ->where('provider_user_id', $providerUserId)
            ->where('status', Job::STATUS_COMPLETADO);

        // Conteo TOTAL de Trabajos completados (incluye los sin fecha estimada):
        // el Requisito 9.6 exige no afectar este conteo mostrado en el Perfil.
        $completedCount = (clone $completedQuery)->count();

        // Base del cálculo: solo Trabajos completados CON fecha estimada
        // registrada (Requisitos 9.1, 9.6).
        $withEstimateQuery = (clone $completedQuery)->whereNotNull('estimated_delivery_date');
        $withEstimateCount = (clone $withEstimateQuery)->count();

        if ($withEstimateCount === 0) {
            // Ningún Trabajo completado tiene fecha estimada: no hay porcentaje
            // calculable; se cachea null => "Sin datos suficientes" (Req 9.5).
            $complianceIndex = null;
        } else {
            // Trabajos entregados en tiempo: finalización real <= estimada.
            $onTimeCount = (clone $withEstimateQuery)
                ->whereColumn('actual_completion_date', '<=', 'estimated_delivery_date')
                ->count();

            // Porcentaje entero entre 0 y 100 (Requisito 9.1).
            $complianceIndex = (int) round(100 * $onTimeCount / $withEstimateCount);
        }

        $profile->forceFill([
            'completed_jobs_count' => $completedCount,
            'compliance_index' => $complianceIndex,
        ])->save();

        return $complianceIndex;
    }

    /**
     * Indica si el Trabajo admite que el Prestador lo marque como completado:
     * debe estar "en_progreso" y sin finalización marcada todavía (Req 7.3).
     */
    private function isAwaitingProviderCompletion(Job $job): bool
    {
        return $job->status === Job::STATUS_EN_PROGRESO
            && $job->actual_completion_date === null;
    }

    /**
     * Indica si el Trabajo está a la espera de que el Cliente confirme o rechace
     * la finalización: "en_progreso" con `actual_completion_date` ya fijada
     * (Requisitos 7.4, 7.6).
     */
    private function isAwaitingClientDecision(Job $job): bool
    {
        return $job->status === Job::STATUS_EN_PROGRESO
            && $job->actual_completion_date !== null;
    }
}

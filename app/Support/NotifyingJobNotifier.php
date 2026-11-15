<?php

namespace App\Support;

use App\Contracts\JobNotifier;
use App\Models\Job;
use App\Services\NotificationService;

/**
 * Adaptador del seam JobNotifier que delega en el Gestor_Notificaciones real
 * (NotificationService) — tarea 14.1, Requisitos 12.1, 12.2.
 *
 * Reemplaza al placeholder LogJobNotifier. Traduce cada evento del ciclo de
 * vida de los Trabajos en una notificación in-app, con los destinatarios
 * correctos:
 *   - finalización marcada → el Cliente, para confirmar/rechazar (Requisito 7.3).
 *   - finalización rechazada → el Prestador, con el motivo (Requisito 7.6).
 *   - cancelación solicitada → la contraparte, con el motivo (Requisito 7.8).
 *   - Trabajo cancelado → la contraparte informada (Requisito 7.7).
 *
 * No se toca JobService ni AutoCompleteJobJob: solo cambia el binding del
 * contrato en AppServiceProvider.
 */
class NotifyingJobNotifier implements JobNotifier
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function notifyClientOfCompletion(Job $job): void
    {
        $this->notifications->notify($job->client_user_id, 'job.completion_requested', [
            'job_id' => $job->id,
            'provider_user_id' => $job->provider_user_id,
            'actor_name' => $job->provider?->name,
            'quote_request_title' => $this->requestTitle($job),
        ]);
    }

    public function notifyProviderOfRejection(Job $job, string $reason): void
    {
        $this->notifications->notify($job->provider_user_id, 'job.completion_rejected', [
            'job_id' => $job->id,
            'reason' => $reason,
            'actor_name' => $job->client?->name,
            'quote_request_title' => $this->requestTitle($job),
        ]);
    }

    public function notifyCancellationRequested(Job $job, int $recipientUserId, string $reason): void
    {
        $this->notifications->notify($recipientUserId, 'job.cancellation_requested', [
            'job_id' => $job->id,
            'reason' => $reason,
            'actor_name' => $this->counterpartName($job, $recipientUserId),
            'quote_request_title' => $this->requestTitle($job),
        ]);
    }

    public function notifyJobCancelled(Job $job, int $recipientUserId): void
    {
        $this->notifications->notify($recipientUserId, 'job.cancelled', [
            'job_id' => $job->id,
            'actor_name' => $this->counterpartName($job, $recipientUserId),
            'quote_request_title' => $this->requestTitle($job),
        ]);
    }

    /** Titulo de la Solicitud de origen; null en cotizaciones directas. */
    private function requestTitle(Job $job): ?string
    {
        return $job->quote?->quoteRequest?->title;
    }

    /** Nombre de la parte del Trabajo distinta del destinatario. */
    private function counterpartName(Job $job, int $recipientUserId): ?string
    {
        return (int) $job->client_user_id === $recipientUserId
            ? $job->provider?->name
            : $job->client?->name;
    }
}

<?php

namespace App\Contracts;

use App\Models\Job;

/**
 * Seam de notificación para el ciclo de vida de los Trabajos (Requisitos 7.3,
 * 7.5, 7.6).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 9.2 / tarea 14.1)
 * -----------------------------------------------------------------------------
 * El Gestor_Notificaciones real (NotificationService) se implementa en la
 * tarea 14.1. Para no acoplar la tarea 9.2 a un componente aún inexistente,
 * JobService (y AutoCompleteJobJob) dependen de esta interfaz en lugar de una
 * implementación concreta. Hoy se resuelve a una implementación placeholder
 * (LogJobNotifier) registrada en AppServiceProvider; la tarea 14.1 reemplazará
 * ese binding por un adaptador que delegue en NotificationService SIN tocar
 * JobService ni este contrato.
 * -----------------------------------------------------------------------------
 */
interface JobNotifier
{
    /**
     * Notifica al Cliente que el Prestador ha marcado el Trabajo como
     * completado y debe confirmar o rechazar la finalización (Requisito 7.3).
     * El destinatario es el Cliente (`client_user_id`).
     */
    public function notifyClientOfCompletion(Job $job): void;

    /**
     * Notifica al Prestador que el Cliente ha rechazado la finalización del
     * Trabajo, incluyendo el motivo del rechazo (Requisito 7.6). El
     * destinatario es el Prestador (`provider_user_id`).
     */
    public function notifyProviderOfRejection(Job $job, string $reason): void;

    /**
     * Notifica a la OTRA parte que una de las partes ha solicitado cancelar el
     * Trabajo de mutuo acuerdo y que su confirmación queda pendiente (Requisito
     * 7.8). `$recipientUserId` es el usuario a notificar (la contraparte de
     * quien solicitó), `$reason` el motivo aportado por el solicitante.
     */
    public function notifyCancellationRequested(Job $job, int $recipientUserId, string $reason): void;

    /**
     * Notifica a ambas partes que el Trabajo ha quedado cancelado de mutuo
     * acuerdo (Requisito 7.7). El destinatario informado es la contraparte de
     * quien confirmó la cancelación (`$recipientUserId`).
     */
    public function notifyJobCancelled(Job $job, int $recipientUserId): void;
}

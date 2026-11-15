<?php

namespace App\Contracts;

use App\Models\QuoteRequest;

/**
 * Seam de notificación para el ciclo de vida de las Solicitudes_Cotización
 * (Requisitos 4.6, 4.7).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 7.1 / tarea 14.1)
 * -----------------------------------------------------------------------------
 * El Gestor_Notificaciones (NotificationService) se implementa más adelante en
 * la tarea 14.1. Para no acoplar la tarea 7.1 a un componente aún inexistente,
 * QuoteRequestService depende de esta interfaz en lugar de una implementación
 * concreta. Hoy se resuelve a una implementación "log/null"
 * (LogQuoteRequestNotifier) registrada en AppServiceProvider; la tarea 14.1
 * reemplazará ese binding por un adaptador que delegue en NotificationService
 * SIN tocar QuoteRequestService.
 * -----------------------------------------------------------------------------
 */
interface QuoteRequestNotifier
{
    /**
     * Notifica a todos los Prestadores que han postulado que la Solicitud
     * fue editada mientras estaba "abierta" (Requisito 4.6).
     */
    public function notifyApplicantsOfEdit(QuoteRequest $quoteRequest): void;

    /**
     * Notifica a todos los Prestadores que han postulado que la Solicitud
     * fue cerrada manualmente por el Cliente (Requisito 4.7).
     */
    public function notifyApplicantsOfClosure(QuoteRequest $quoteRequest): void;

    /**
     * Notifica al Prestador seleccionado como ganador de una Solicitud
     * (Requisito 4.5). La selección mueve la Solicitud a estado "en proceso".
     *
     * @param  int  $providerUserId  ID del usuario Prestador seleccionado.
     */
    public function notifyProviderSelected(QuoteRequest $quoteRequest, int $providerUserId): void;

    /**
     * Notifica al Cliente dueño de la Solicitud que un Prestador ha postulado
     * (Requisito 4.4). El destinatario es el Cliente, no los postulantes.
     *
     * @param  int  $providerUserId  ID del usuario Prestador que postuló.
     */
    public function notifyClientOfApplication(QuoteRequest $quoteRequest, int $providerUserId): void;
}

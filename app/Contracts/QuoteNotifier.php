<?php

namespace App\Contracts;

use App\Models\Quote;

/**
 * Seam de notificación para el ciclo de vida de las Cotizaciones
 * (Requisito 5.4; también 5.8 y 6.3 en tareas posteriores).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 8.1 / tarea 14.1)
 * -----------------------------------------------------------------------------
 * El Gestor_Notificaciones real (NotificationService) se implementa en la
 * tarea 14.1. Para no acoplar la tarea 8.1 a un componente aún inexistente,
 * QuoteService depende de esta interfaz en lugar de una implementación
 * concreta. Hoy se resuelve a una implementación placeholder
 * (LogQuoteNotifier) registrada en AppServiceProvider; la tarea 14.1
 * reemplazará ese binding por un adaptador que delegue en NotificationService
 * SIN tocar QuoteService.
 *
 * El contrato se diseña de forma aditiva: la tarea 8.5 (aceptación/rechazo) y
 * la 8.8 (expiración automática) añadirán aquí nuevos métodos sin modificar los
 * ya existentes.
 * -----------------------------------------------------------------------------
 */
interface QuoteNotifier
{
    /**
     * Notifica al Cliente receptor que ha recibido una nueva Cotización en
     * estado "pendiente" (Requisito 5.4). El destinatario es el Cliente
     * asociado a la Cotización (`client_user_id`).
     */
    public function notifyClientOfNewQuote(Quote $quote): void;

    /**
     * Notifica a AMBAS partes (Prestador y Cliente) que una Cotización ha
     * vencido, al pasar automáticamente a estado "expirada" (Requisito 6.3).
     *
     * Lo invoca ExpireQuoteJob (tarea 8.8) tras cambiar el estado de la
     * Cotización a "expirada". Los destinatarios son el Prestador
     * (`provider_user_id`) y el Cliente (`client_user_id`).
     */
    public function notifyQuoteExpired(Quote $quote): void;

    /**
     * Notifica al Prestador que su Cotización ha sido rechazada por el Cliente
     * (Requisito 5.8). Lo invoca QuoteService::rejectQuote (tarea 8.5) tras
     * cambiar el estado de la Cotización a "rechazada". El destinatario es el
     * Prestador (`provider_user_id`).
     */
    public function notifyQuoteRejected(Quote $quote): void;

    /**
     * Notifica al Prestador que un Cliente le ha solicitado una Cotización
     * directa en estado "solicitada" (Requisito 10.1). Lo invoca
     * QuoteService::requestDirectQuote (tarea 11.1) tras crear la Cotización.
     * El destinatario es el Prestador (`provider_user_id`).
     */
    public function notifyProviderOfDirectRequest(Quote $quote): void;

    /**
     * Notifica al Cliente que su solicitud de Cotización directa no fue
     * respondida en el plazo de 7 días y pasó a estado "sin_respuesta"
     * (Requisito 10.3). Lo invoca ExpireDirectQuoteJob (tarea 11.1) tras
     * cambiar el estado de la Cotización a "sin_respuesta". El destinatario es
     * el Cliente (`client_user_id`).
     */
    public function notifyClientOfDirectNoResponse(Quote $quote): void;
}

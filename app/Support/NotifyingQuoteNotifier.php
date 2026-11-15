<?php

namespace App\Support;

use App\Contracts\QuoteNotifier;
use App\Models\Quote;
use App\Services\NotificationService;

/**
 * Adaptador del seam QuoteNotifier que delega en el Gestor_Notificaciones real
 * (NotificationService) — tarea 14.1, Requisitos 12.1, 12.2.
 *
 * Reemplaza al placeholder LogQuoteNotifier. Traduce cada evento del ciclo de
 * vida de las Cotizaciones en una notificación in-app, con los destinatarios
 * correctos:
 *   - nueva Cotización → el Cliente receptor (Requisito 5.4).
 *   - Cotización expirada → ambas partes (Requisito 6.3).
 *   - Cotización rechazada → el Prestador que la creó (Requisito 5.8).
 *   - solicitud de Cotización directa → el Prestador destinatario (Requisito 10.1).
 *   - solicitud directa sin respuesta → el Cliente solicitante (Requisito 10.3).
 *
 * No se toca QuoteService: solo cambia el binding del contrato en
 * AppServiceProvider.
 */
class NotifyingQuoteNotifier implements QuoteNotifier
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function notifyClientOfNewQuote(Quote $quote): void
    {
        $this->notifications->notify($quote->client_user_id, 'quote.received', $this->payload($quote, [
            'provider_user_id' => $quote->provider_user_id,
            'actor_name' => $quote->provider?->name,
        ]));
    }

    public function notifyQuoteExpired(Quote $quote): void
    {
        $this->notifications->notifyMany(
            [$quote->provider_user_id, $quote->client_user_id],
            'quote.expired',
            $this->payload($quote),
        );
    }

    public function notifyQuoteRejected(Quote $quote): void
    {
        $this->notifications->notify($quote->provider_user_id, 'quote.rejected', $this->payload($quote, [
            'client_user_id' => $quote->client_user_id,
            'actor_name' => $quote->client?->name,
        ]));
    }

    public function notifyProviderOfDirectRequest(Quote $quote): void
    {
        $this->notifications->notify($quote->provider_user_id, 'quote.direct_requested', $this->payload($quote, [
            'client_user_id' => $quote->client_user_id,
            'actor_name' => $quote->client?->name,
        ]));
    }

    public function notifyClientOfDirectNoResponse(Quote $quote): void
    {
        $this->notifications->notify($quote->client_user_id, 'quote.direct_no_response', $this->payload($quote, [
            'provider_user_id' => $quote->provider_user_id,
            'actor_name' => $quote->provider?->name,
        ]));
    }

    /**
     * Datos base comunes a las notificaciones de Cotización, con extras
     * opcionales según el evento.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(Quote $quote, array $extra = []): array
    {
        return array_merge([
            'quote_id' => $quote->id,
            'quote_request_id' => $quote->quote_request_id,
            'quote_request_title' => $quote->quoteRequest?->title,
            'provider_user_id' => $quote->provider_user_id,
            'client_user_id' => $quote->client_user_id,
        ], $extra);
    }
}

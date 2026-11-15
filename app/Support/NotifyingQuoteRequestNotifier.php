<?php

namespace App\Support;

use App\Contracts\QuoteRequestNotifier;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\NotificationService;

/**
 * Adaptador del seam QuoteRequestNotifier que delega en el Gestor_Notificaciones
 * real (NotificationService) — tarea 14.1, Requisitos 12.1, 12.2.
 *
 * Reemplaza al placeholder LogQuoteRequestNotifier (que solo registraba la
 * intención en el log). Traduce cada evento del ciclo de vida de las
 * Solicitudes_Cotización en una notificación in-app persistida y transmitida
 * por Reverb, resolviendo el conjunto correcto de destinatarios:
 *   - edición/cierre → todos los Prestadores que postularon (Requisitos 4.6, 4.7).
 *   - selección de ganador → el Prestador seleccionado (Requisito 4.5).
 *   - nueva postulación → el Cliente dueño de la Solicitud (Requisito 4.4).
 *
 * No se toca QuoteRequestService: solo cambia el binding del contrato en
 * AppServiceProvider.
 */
class NotifyingQuoteRequestNotifier implements QuoteRequestNotifier
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function notifyApplicantsOfEdit(QuoteRequest $quoteRequest): void
    {
        $this->notifyApplicants('quote_request.edited', $quoteRequest);
    }

    public function notifyApplicantsOfClosure(QuoteRequest $quoteRequest): void
    {
        $this->notifyApplicants('quote_request.closed', $quoteRequest);
    }

    public function notifyProviderSelected(QuoteRequest $quoteRequest, int $providerUserId): void
    {
        $this->notifications->notify($providerUserId, 'quote_request.provider_selected', [
            'quote_request_id' => $quoteRequest->id,
            'title' => $quoteRequest->title,
            'quote_request_title' => $quoteRequest->title,
        ]);
    }

    public function notifyClientOfApplication(QuoteRequest $quoteRequest, int $providerUserId): void
    {
        $this->notifications->notify($quoteRequest->client_user_id, 'quote_request.application_received', [
            'quote_request_id' => $quoteRequest->id,
            'title' => $quoteRequest->title,
            'provider_user_id' => $providerUserId,
            'quote_request_title' => $quoteRequest->title,
            'actor_name' => User::find($providerUserId)?->name,
        ]);
    }

    /**
     * Notifica a cada Prestador postulante de la Solicitud. Resuelve el conjunto
     * de destinatarios igual que el placeholder: los que postularon.
     */
    private function notifyApplicants(string $type, QuoteRequest $quoteRequest): void
    {
        $applicantIds = $quoteRequest->applications()
            ->pluck('provider_user_id')
            ->all();

        if ($applicantIds === []) {
            return;
        }

        $this->notifications->notifyMany($applicantIds, $type, [
            'quote_request_id' => $quoteRequest->id,
            'title' => $quoteRequest->title,
            'quote_request_title' => $quoteRequest->title,
        ]);
    }
}

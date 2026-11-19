<?php

namespace App\Http\Controllers;

use App\Exceptions\DirectQuoteRequestException;
use App\Exceptions\QuoteNotActionableException;
use App\Exceptions\QuoteNotAuthorizedException;
use App\Exceptions\QuoteNotCreatableException;
use App\Http\Requests\IndexMyQuotesRequest;
use App\Http\Requests\RespondDirectQuoteRequest;
use App\Http\Requests\StoreDirectQuoteRequest;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Services\QuoteService;
use App\Support\AvatarPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Controlador de Cotizaciones (/api/v1/quotes).
 *
 * Tarea 8.1 (Requisitos 5.1-5.6, 6.1, 6.5):
 *   - POST /quotes                      Crear cotización (Prestador autenticado).
 *   - GET  /quotes/{id}                 Ver cotización (partes involucradas).
 *   - GET  /quotes/request/{id}         Vista comparativa de cotizaciones de una
 *                                       solicitud (Cliente dueño).
 *
 * La creación exige auth:sanctum + rol `prestador` (middleware `role.provider`).
 * La validación de rangos la hace StoreQuoteRequest, devolviendo HTTP 422 sin
 * persistir (Requisito 5.5). Un intento de crear sobre una solicitud que no
 * está "abierta" es un error de negocio que se traduce a HTTP 409.
 *
 * La aceptación/rechazo (POST /{id}/accept, /{id}/reject) es la tarea 8.5 y se
 * añadirá a este controlador de forma aditiva.
 */
class QuoteController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly QuoteService $service) {}

    /**
     * POST /api/v1/quotes  (auth:sanctum + role.provider — Prestador)
     *
     * Crea una Cotización pública en estado "pendiente" para la Solicitud
     * indicada, calcula el total y adjunta hasta 5 imágenes (Requisitos 5.1-5.4,
     * 6.1).
     */
    public function store(StoreQuoteRequest $request): JsonResponse
    {
        $attachments = $request->file('attachments', []) ?? [];

        try {
            $quote = $this->service->createQuote(
                $request->user(),
                $request->validated(),
                is_array($attachments) ? $attachments : [$attachments],
            );
        } catch (QuoteNotAuthorizedException $e) {
            return $this->error($e->getMessage(), status: 403);
        } catch (QuoteNotCreatableException $e) {
            return $this->error($e->getMessage(), status: 409);
        }

        return $this->created(
            data: ['quote' => $this->present($quote)],
            message: 'Cotización enviada correctamente.',
        );
    }

    /**
     * GET /api/v1/quotes/sent  (auth:sanctum + role.provider)
     */
    public function sent(IndexMyQuotesRequest $request): JsonResponse
    {
        $paginator = $this->service
            ->paginateSentBy($request->user(), $request->statusFilter(), $request->perPage())
            ->through(fn (Quote $quote): array => $this->summary($quote));

        return $this->paginated($paginator, 'Cotizaciones enviadas.');
    }

    /**
     * GET /api/v1/quotes/received  (auth:sanctum)
     */
    public function received(IndexMyQuotesRequest $request): JsonResponse
    {
        if ($request->isAwaitingView()) {
            $awaiting = $this->service
                ->paginateAwaitingBy($request->user(), $request->statusFilter(), $request->perPage())
                ->through(fn (Quote $quote): array => [
                    ...$this->summary($quote),
                    'message_excerpt' => $this->messageExcerpt($quote),
                ]);

            return $this->paginated($awaiting, 'Solicitudes de cotización en espera de respuesta.');
        }

        $paginator = $this->service
            ->paginateReceivedBy($request->user(), $request->statusFilter(), $request->perPage())
            ->through(fn (Quote $quote): array => $this->summary($quote));

        return $this->paginated($paginator, 'Cotizaciones recibidas.');
    }

    /**
     * Extracto del mensaje de una solicitud directa; null si está vacío o es
     * el mensaje por defecto.
     */
    private function messageExcerpt(Quote $quote): ?string
    {
        $message = trim((string) $quote->service_description);

        if ($message === '' || $message === QuoteService::DIRECT_REQUEST_DEFAULT_MESSAGE) {
            return null;
        }

        return Str::limit($message, 140, '…');
    }

    /**
     * Resumen de cotización para listados (sin ítems ni adjuntos).
     *
     * @return array<string, mixed>
     */
    private function summary(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'type' => $quote->type,
            'status' => $quote->status,
            'total_price' => (float) $quote->total_price,
            'delivery_days' => $quote->delivery_days,
            'expires_at' => $quote->expires_at?->toDateString(),
            'created_at' => $quote->created_at?->toIso8601String(),
            'provider_user_id' => $quote->provider_user_id,
            'provider_name' => $quote->provider?->name,
            'client_user_id' => $quote->client_user_id,
            'client_name' => $quote->client?->name,
            ...AvatarPresenter::thumb($quote->provider, 'provider_'),
            ...AvatarPresenter::thumb($quote->client, 'client_'),
            'quote_request_id' => $quote->quote_request_id,
            'quote_request_title' => $quote->quoteRequest?->title,
            'job_id' => $quote->job?->id,
        ];
    }

    /**
     * GET /api/v1/quotes/{quote}  (auth:sanctum — partes involucradas)
     *
     * Muestra una Cotización. Solo el Prestador que la creó o el Cliente
     * receptor pueden consultarla (403 en otro caso).
     */
    public function show(Request $request, Quote $quote): JsonResponse
    {
        if ($denied = $this->denyIfNotParticipant($request, $quote)) {
            return $denied;
        }

        return $this->ok(
            data: ['quote' => $this->present($quote)],
            message: 'Detalle de la cotización.',
        );
    }

    /**
     * GET /api/v1/quotes/request/{quoteRequest}  (auth:sanctum — Cliente dueño)
     *
     * Vista comparativa de las Cotizaciones recibidas para una Solicitud,
     * ordenadas de la más reciente a la más antigua (Requisito 5.6). Solo el
     * Cliente dueño de la Solicitud puede consultarla (403 en otro caso).
     */
    public function forRequest(Request $request, QuoteRequest $quoteRequest): JsonResponse
    {
        if ($quoteRequest->client_user_id !== $request->user()->id) {
            return $this->error(
                'No tienes permiso para ver las cotizaciones de esta solicitud.',
                status: 403,
            );
        }

        $quotes = $this->service->quotesForRequest($quoteRequest)
            ->map(fn (Quote $quote): array => $this->present($quote))
            ->all();

        return $this->ok(
            data: ['quotes' => $quotes],
            message: 'Cotizaciones de la solicitud.',
        );
    }

    /**
     * POST /api/v1/quotes/{quote}/accept  (auth:sanctum — Cliente receptor)
     *
     * El Cliente receptor acepta la Cotización (Requisito 5.7): se marca como
     * "aceptada", las demás Cotizaciones de la misma Solicitud pasan a
     * "no_seleccionada" y se crea el Trabajo asociado.
     *
     * Errores de negocio:
     *  - 403 si el solicitante no es el Cliente receptor.
     *  - 409 si la Cotización está "expirada" (Requisito 6.4) o no está
     *    "pendiente".
     */
    public function accept(Request $request, Quote $quote): JsonResponse
    {
        try {
            $job = $this->service->acceptQuote($quote, $request->user());
        } catch (QuoteNotAuthorizedException $e) {
            return $this->error($e->getMessage(), status: 403);
        } catch (QuoteNotActionableException $e) {
            return $this->error($e->getMessage(), status: 409);
        }

        return $this->ok(
            data: [
                'quote' => $this->present($quote->refresh()),
                'job' => $this->presentJob($job),
            ],
            message: 'Cotización aceptada correctamente.',
        );
    }

    /**
     * POST /api/v1/quotes/{quote}/reject  (auth:sanctum — Cliente receptor)
     *
     * El Cliente receptor rechaza la Cotización (Requisito 5.8): se marca como
     * "rechazada" y se notifica al Prestador.
     *
     * Errores de negocio:
     *  - 403 si el solicitante no es el Cliente receptor.
     *  - 409 si la Cotización está "expirada" (Requisito 6.4) o no está
     *    "pendiente".
     */
    public function reject(Request $request, Quote $quote): JsonResponse
    {
        try {
            $quote = $this->service->rejectQuote($quote, $request->user());
        } catch (QuoteNotAuthorizedException $e) {
            return $this->error($e->getMessage(), status: 403);
        } catch (QuoteNotActionableException $e) {
            return $this->error($e->getMessage(), status: 409);
        }

        return $this->ok(
            data: ['quote' => $this->present($quote->refresh())],
            message: 'Cotización rechazada correctamente.',
        );
    }

    /**
     * POST /api/v1/quotes/direct  (auth:sanctum — Cliente)
     *
     * El Cliente autenticado solicita una Cotización directa a un Prestador
     * concreto (Requisito 10.1): se crea una Cotización `directa` en estado
     * "solicitada" y se notifica al Prestador.
     *
     * Errores de negocio:
     *  - 422 si el Prestador objetivo no tiene el rol activo (Requisito 10.5).
     *  - 409 si el Cliente ya tiene 5 o más solicitudes activas hacia ese
     *    Prestador (Requisito 10.6).
     */
    public function requestDirect(StoreDirectQuoteRequest $request): JsonResponse
    {
        try {
            $quote = $this->service->requestDirectQuote(
                $request->user(),
                $request->validated(),
            );
        } catch (DirectQuoteRequestException $e) {
            return $this->error($e->getMessage(), status: $e->status);
        }

        return $this->created(
            data: ['quote' => $this->present($quote)],
            message: 'Solicitud de cotización directa enviada correctamente.',
        );
    }

    /**
     * POST /api/v1/quotes/direct/{quote}/respond  (auth:sanctum — Prestador objetivo)
     *
     * El Prestador objetivo responde a una solicitud de Cotización directa con
     * una Cotización estandarizada (Requisitos 10.2, 10.4): la Cotización pasa
     * de "solicitada" a "pendiente", se calcula el total y se notifica al
     * Cliente.
     *
     * Errores de negocio:
     *  - 403 si el solicitante no es el Prestador objetivo.
     *  - 409 si la Cotización ya no está "solicitada".
     *  - 422 si el payload está fuera de rango (lo valida RespondDirectQuoteRequest).
     */
    public function respondDirect(RespondDirectQuoteRequest $request, Quote $quote): JsonResponse
    {
        $attachments = $request->file('attachments', []) ?? [];

        try {
            $quote = $this->service->respondToDirectQuote(
                $quote,
                $request->user(),
                $request->validated(),
                is_array($attachments) ? $attachments : [$attachments],
            );
        } catch (QuoteNotAuthorizedException $e) {
            return $this->error($e->getMessage(), status: 403);
        } catch (QuoteNotActionableException $e) {
            return $this->error($e->getMessage(), status: 409);
        }

        return $this->ok(
            data: ['quote' => $this->present($quote)],
            message: 'Cotización enviada correctamente.',
        );
    }

    /**
     * Representación mínima del Trabajo creado al aceptar una Cotización.
     *
     * @return array<string, mixed>
     */
    private function presentJob(Job $job): array
    {
        return [
            'id' => $job->id,
            'quote_id' => $job->quote_id,
            'client_user_id' => $job->client_user_id,
            'provider_user_id' => $job->provider_user_id,
            'status' => $job->status,
            'start_date' => $job->start_date?->toDateString(),
            'estimated_delivery_date' => $job->estimated_delivery_date?->toDateString(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    /**
     * Rechaza con 403 si el usuario autenticado no es parte de la Cotización
     * (ni el Prestador que la creó ni el Cliente receptor). Devuelve null si sí
     * lo es.
     */
    private function denyIfNotParticipant(Request $request, Quote $quote): ?JsonResponse
    {
        $userId = $request->user()->id;

        if ($quote->provider_user_id !== $userId && $quote->client_user_id !== $userId) {
            return $this->error(
                'No tienes permiso para ver esta cotización.',
                status: 403,
            );
        }

        return null;
    }

    /**
     * Representación de una Cotización para la API, incluyendo sus ítems y las
     * URLs de los adjuntos gestionados por Media Library.
     *
     * @return array<string, mixed>
     */
    private function present(Quote $quote): array
    {
        $quote->loadMissing(['items', 'provider:id,name', 'provider.media', 'client:id,name', 'client.media', 'media']);

        // `load` (no `loadMissing`): garantiza la columna `status` aunque la
        // relación se haya cargado antes con menos columnas, y evita un
        // `job` obsoleto (null) tras aceptar la cotización.
        $quote->load(['quoteRequest:id,title,status', 'job:id,quote_id,status']);

        return [
            'id' => $quote->id,
            'provider_user_id' => $quote->provider_user_id,
            'provider_name' => $quote->provider?->name,
            'client_user_id' => $quote->client_user_id,
            'client_name' => $quote->client?->name,
            ...AvatarPresenter::thumb($quote->provider, 'provider_'),
            ...AvatarPresenter::thumb($quote->client, 'client_'),
            'quote_request_id' => $quote->quote_request_id,
            'type' => $quote->type,
            'service_category_id' => $quote->service_category_id,
            'service_description' => $quote->service_description,
            'total_price' => (float) $quote->total_price,
            'delivery_days' => $quote->delivery_days,
            'expires_at' => $quote->expires_at?->toDateString(),
            'status' => $quote->status,
            'quote_request' => $quote->quoteRequest === null ? null : [
                'id' => $quote->quoteRequest->id,
                'title' => $quote->quoteRequest->title,
                'status' => $quote->quoteRequest->status,
            ],
            'job_id' => $quote->job?->id,
            'job_status' => $quote->job?->status,
            'items' => $quote->items->map(fn ($item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
            ])->values()->all(),
            'attachments' => $quote->getMedia(Quote::ATTACHMENTS_MEDIA_COLLECTION)
                ->map(fn ($media): array => [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                    'thumb_url' => $media->hasGeneratedConversion('thumb')
                        ? $media->getUrl('thumb')
                        : null,
                ])->values()->all(),
            'created_at' => $quote->created_at?->toIso8601String(),
            'updated_at' => $quote->updated_at?->toIso8601String(),
        ];
    }
}

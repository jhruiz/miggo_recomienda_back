<?php

namespace App\Services;

use App\Contracts\QuoteNotifier;
use App\Exceptions\DirectQuoteRequestException;
use App\Exceptions\QuoteNotActionableException;
use App\Exceptions\QuoteNotAuthorizedException;
use App\Exceptions\QuoteNotCreatableException;
use App\Jobs\ExpireDirectQuoteJob;
use App\Jobs\ExpireQuoteJob;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de Cotizaciones (Gestor_Cotizaciones del dominio).
 *
 * Cubre la tarea 8.1: creación de Cotizaciones estandarizadas con ítems,
 * cálculo del precio total, adjuntos vía Media Library y notificación al
 * Cliente receptor (Requisitos 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 6.1, 6.5).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 8.1 / tareas 8.5, 8.8, 14.1)
 * -----------------------------------------------------------------------------
 * - La notificación al Cliente receptor (Requisito 5.4) se delega a través del
 *   seam App\Contracts\QuoteNotifier, que hoy resuelve a una implementación
 *   placeholder (LogQuoteNotifier). La tarea 14.1 sustituirá el binding por un
 *   adaptador sobre NotificationService sin tocar este servicio.
 * - La aceptación/rechazo (tarea 8.5) se añadirá aquí de forma ADITIVA (nuevos
 *   métodos), sin modificar createQuote().
 * - La expiración automática (tarea 8.8) se implementa despachando
 *   ExpireQuoteJob con delay hasta `expires_at` al final de createQuote,
 *   sin alterar la lógica previa. El Job vive en App\Jobs\ExpireQuoteJob.
 * -----------------------------------------------------------------------------
 */
class QuoteService
{
    public const DIRECT_REQUEST_DEFAULT_MESSAGE = 'Solicitud de cotización directa.';

    public function __construct(
        private readonly QuoteNotifier $notifier,
        private readonly JobService $jobService,
    ) {
    }

    /**
     * Crea una Cotización pública en respuesta a una Solicitud_Cotización
     * (Requisitos 5.1-5.5, 6.1, 6.5).
     *
     * Los datos llegan ya validados por el Form Request (rangos de precio,
     * cantidad, número de ítems, descripción, delivery_days y expiration_days);
     * un payload fuera de rango se rechaza con HTTP 422 antes de llegar aquí,
     * por lo que nunca se persiste nada inválido (Requisito 5.5).
     *
     * Reglas aplicadas:
     *  - El Cliente receptor se deriva del dueño de la Solicitud, no del
     *    cliente del payload (evita suplantación).
     *  - La Solicitud debe estar "abierta"; en otro caso se lanza
     *    QuoteNotCreatableException (HTTP 409).
     *  - `total_price` = Σ(unit_price × quantity) de todos los ítems; cada
     *    `subtotal` se calcula y persiste por ítem (Requisito 5.2).
     *  - Estado inicial "pendiente" (Requisito 5.4).
     *  - `expires_at` = hoy + expiration_days (Requisito 6.1).
     *  - Se adjuntan hasta 5 imágenes vía Media Library (Requisito 5.3).
     *
     * Toda la escritura (cotización + ítems + adjuntos) se envuelve en una
     * transacción para no dejar cotizaciones a medio construir.
     *
     * @param  array{
     *     quote_request_id: int,
     *     service_description: string,
     *     items: array<int, array{description: string, unit_price: int|float|string, quantity: int}>,
     *     delivery_days: int,
     *     expiration_days: int
     * }  $data
     * @param  array<int, UploadedFile>  $attachments
     *
     * @throws QuoteNotCreatableException si la Solicitud no está "abierta".
     */
    public function createQuote(User $provider, array $data, array $attachments = []): Quote
    {
        /** @var QuoteRequest $quoteRequest */
        $quoteRequest = QuoteRequest::query()->findOrFail($data['quote_request_id']);

        if ($quoteRequest->client_user_id === $provider->id) {
            throw new QuoteNotAuthorizedException('No puedes cotizar tu propia solicitud de cotización.');
        }

        if ($quoteRequest->status !== QuoteRequest::STATUS_ABIERTA) {
            throw QuoteNotCreatableException::requestNotOpen($quoteRequest);
        }

        $items = $this->normalizeItems($data['items']);
        $totalPrice = $this->sumSubtotals($items);
        $expiresAt = now()->startOfDay()->addDays((int) $data['expiration_days']);

        $quote = DB::transaction(function () use ($provider, $quoteRequest, $data, $items, $totalPrice, $expiresAt, $attachments): Quote {
            /** @var Quote $quote */
            $quote = Quote::create([
                'provider_user_id' => $provider->id,
                'client_user_id' => $quoteRequest->client_user_id,
                'quote_request_id' => $quoteRequest->id,
                'type' => Quote::TYPE_PUBLICA,
                'service_description' => $data['service_description'],
                'total_price' => $totalPrice,
                'delivery_days' => (int) $data['delivery_days'],
                'expires_at' => $expiresAt,
                'status' => Quote::STATUS_PENDIENTE,
            ]);

            $quote->items()->createMany($items);

            foreach ($attachments as $attachment) {
                $quote->addMedia($attachment)
                    ->toMediaCollection(Quote::ATTACHMENTS_MEDIA_COLLECTION);
            }

            return $quote;
        });

        // Notifica al Cliente receptor de la nueva Cotización (Requisito 5.4).
        $this->notifier->notifyClientOfNewQuote($quote);

        // Programa la expiración automática: ExpireQuoteJob con delay hasta
        // `expires_at` (tarea 8.8, Requisitos 6.2, 6.3; decisión técnica 1 del
        // diseño: Jobs con delay en lugar de comandos programados). Al llegar
        // la fecha, el Job pasa la Cotización a "expirada" solo si sigue
        // "pendiente" y notifica a ambas partes. Añadido de forma ADITIVA.
        ExpireQuoteJob::dispatch($quote->id)->delay($expiresAt);

        return $quote->refresh();
    }

    /**
     * Cotizaciones enviadas por el Prestador (todos los estados, incluye
     * directas "solicitada").
     */
    public function paginateSentBy(User $provider, ?string $status = null, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->listingQuery()
            ->where('provider_user_id', $provider->id)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->paginate($perPage);
    }

    /**
     * Cotizaciones recibidas por el Cliente (excluye "solicitada" y "sin_respuesta").
     */
    public function paginateReceivedBy(User $client, ?string $status = null, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->listingQuery()
            ->where('client_user_id', $client->id)
            ->whereNotIn('status', [Quote::STATUS_SOLICITADA, Quote::STATUS_SIN_RESPUESTA])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->paginate($perPage);
    }

    /**
     * Solicitudes de Cotización directa del Cliente aún sin responder
     * ("solicitada" y "sin_respuesta"), o solo las del estado indicado.
     */
    public function paginateAwaitingBy(User $client, ?string $status = null, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->listingQuery()
            ->where('client_user_id', $client->id)
            ->where('type', Quote::TYPE_DIRECTA)
            ->when(
                $status !== null,
                fn ($q) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', [Quote::STATUS_SOLICITADA, Quote::STATUS_SIN_RESPUESTA]),
            )
            ->paginate($perPage);
    }

    private function listingQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Quote::query()
            ->with(['provider:id,name', 'provider.media', 'client:id,name', 'client.media', 'quoteRequest:id,title', 'job:id,quote_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Cotizaciones recibidas para una misma Solicitud_Cotización, en vista
     * comparativa ordenada por fecha de envío de la más reciente a la más
     * antigua (Requisito 5.6).
     *
     * El control de acceso (solo el Cliente dueño de la Solicitud) lo aplica el
     * controlador antes de invocar este método.
     *
     * @return Collection<int, Quote>
     */
    public function quotesForRequest(QuoteRequest $quoteRequest): Collection
    {
        return $quoteRequest->quotes()
            ->with(['items', 'provider:id,name', 'provider.media', 'client:id,name', 'client.media', 'media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * El Cliente receptor acepta una Cotización (Requisito 5.7).
     *
     * Dentro de una única transacción:
     *  - Marca ESTA Cotización como "aceptada".
     *  - Marca las DEMÁS Cotizaciones de la misma Solicitud_Cotización como
     *    "no_seleccionada" (solo las que siguen "pendiente"; no se tocan las que
     *    ya estén en estado terminal como "rechazada" o "expirada").
     *  - Crea el Trabajo asociado vía JobService::createJob() (relación 1:1,
     *    idempotente).
     *
     * Reglas de acceso y estado:
     *  - Solo el Cliente receptor (`client_user_id`) puede aceptar; en otro caso
     *    se lanza QuoteNotAuthorizedException (HTTP 403).
     *  - Una Cotización "expirada" no puede aceptarse (Requisito 6.4): se lanza
     *    QuoteNotActionableException (HTTP 409).
     *  - Solo una Cotización "pendiente" puede aceptarse; cualquier otro estado
     *    terminal (aceptada/rechazada/no_seleccionada) también produce 409.
     *
     * @throws QuoteNotAuthorizedException si el usuario no es el Cliente receptor.
     * @throws QuoteNotActionableException si la Cotización no está "pendiente".
     */
    public function acceptQuote(Quote $quote, User $client): Job
    {
        $this->assertActionable($quote, $client);

        return DB::transaction(function () use ($quote): Job {
            $quote->status = Quote::STATUS_ACEPTADA;
            $quote->save();

            // Descarta las demás Cotizaciones "pendiente" de la misma Solicitud
            // marcándolas como "no_seleccionada" (Requisito 5.7). Las que ya
            // estén en estado terminal (rechazada/expirada) no se modifican.
            if ($quote->quote_request_id !== null) {
                Quote::query()
                    ->where('quote_request_id', $quote->quote_request_id)
                    ->where('id', '!=', $quote->id)
                    ->where('status', Quote::STATUS_PENDIENTE)
                    ->update(['status' => Quote::STATUS_NO_SELECCIONADA]);
            }

            // Crea el Trabajo originado por la aceptación (Requisito 5.7 / 7.1).
            return $this->jobService->createJob($quote);
        });
    }

    /**
     * El Cliente receptor rechaza una Cotización (Requisito 5.8).
     *
     * Marca la Cotización como "rechazada" y notifica al Prestador a través del
     * seam QuoteNotifier. Aplica las mismas reglas de acceso y estado que
     * acceptQuote (solo el Cliente receptor; solo "pendiente"; una Cotización
     * "expirada" no puede rechazarse, Requisito 6.4).
     *
     * @throws QuoteNotAuthorizedException si el usuario no es el Cliente receptor.
     * @throws QuoteNotActionableException si la Cotización no está "pendiente".
     */
    public function rejectQuote(Quote $quote, User $client): Quote
    {
        $this->assertActionable($quote, $client);

        $quote->status = Quote::STATUS_RECHAZADA;
        $quote->save();

        // Notifica al Prestador que su Cotización fue rechazada (Requisito 5.8).
        $this->notifier->notifyQuoteRejected($quote);

        return $quote;
    }

    /**
     * Un Cliente solicita una Cotización directa a un Prestador concreto
     * (tarea 11.1, Requisitos 10.1, 10.3, 10.5, 10.6).
     *
     * Crea una Cotización `type=directa` en estado "solicitada" asociada al
     * Prestador objetivo, sin Solicitud_Cotización previa
     * (`quote_request_id = null`). Reglas:
     *
     *  - El Prestador objetivo debe tener el rol `prestador` activo; en otro
     *    caso se lanza DirectQuoteRequestException::providerUnavailable()
     *    (HTTP 422, Requisito 10.5).
     *  - El Cliente no puede acumular 5 o más solicitudes directas activas
     *    (estado "solicitada" o "sin_respuesta") hacia ESE mismo Prestador; en
     *    otro caso se lanza DirectQuoteRequestException::activeLimitReached()
     *    (HTTP 409, Requisito 10.6).
     *
     * La Cotización "solicitada" aún no tiene formato estandarizado (eso lo
     * aporta el Prestador al responder, Requisito 10.2/10.4), por lo que se
     * guarda con valores iniciales neutros: `total_price = 0`, `delivery_days
     * = 0` y `service_description` con el mensaje del Cliente (o un texto por
     * defecto). `expires_at` guarda el plazo de respuesta de 7 días calendario
     * (Requisito 10.2), usado por ExpireDirectQuoteJob (Requisito 10.3).
     *
     * Tras crear la Cotización se notifica al Prestador (Requisito 10.1) y se
     * programa ExpireDirectQuoteJob con delay de 7 días (Requisito 10.3).
     *
     * @param  array{provider_user_id: int, message?: string|null}  $data
     *
     * @throws DirectQuoteRequestException
     */
    public function requestDirectQuote(User $client, array $data): Quote
    {
        /** @var User $provider */
        $provider = User::query()->findOrFail($data['provider_user_id']);

        // Requisito 10.5: el Prestador objetivo debe tener el rol activo.
        if (! $provider->hasRole('prestador')) {
            throw DirectQuoteRequestException::providerUnavailable();
        }

        // Requisito 10.6: límite de 5 solicitudes directas activas hacia el
        // mismo Prestador (estados "solicitada" o "sin_respuesta").
        $activeCount = Quote::query()
            ->where('type', Quote::TYPE_DIRECTA)
            ->where('client_user_id', $client->id)
            ->where('provider_user_id', $provider->id)
            ->whereIn('status', [Quote::STATUS_SOLICITADA, Quote::STATUS_SIN_RESPUESTA])
            ->count();

        if ($activeCount >= DirectQuoteRequestException::MAX_ACTIVE_REQUESTS) {
            throw DirectQuoteRequestException::activeLimitReached();
        }

        // Plazo de respuesta de 7 días calendario (Requisitos 10.2, 10.3).
        $expiresAt = now()->startOfDay()->addDays(7);

        $message = isset($data['message']) && $data['message'] !== null && $data['message'] !== ''
            ? $data['message']
            : self::DIRECT_REQUEST_DEFAULT_MESSAGE;

        /** @var Quote $quote */
        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => null,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => $message,
            'total_price' => 0,
            'delivery_days' => 0,
            'expires_at' => $expiresAt,
            'status' => Quote::STATUS_SOLICITADA,
        ]);

        // Notifica al Prestador de la solicitud directa (Requisito 10.1).
        $this->notifier->notifyProviderOfDirectRequest($quote);

        // Programa el vencimiento del plazo de respuesta: a los 7 días, si sigue
        // "solicitada", pasa a "sin_respuesta" y notifica al Cliente (Req 10.3).
        ExpireDirectQuoteJob::dispatch($quote->id)->delay($expiresAt);

        return $quote->refresh();
    }

    /**
     * El Prestador objetivo responde a una solicitud de Cotización directa con
     * una Cotización estandarizada (tarea 11.1, Requisitos 10.2, 10.4).
     *
     * Aplica las MISMAS reglas de formato del Requisito 5 que createQuote
     * (ítems validados por el Form Request, cálculo de `total_price`,
     * delivery_days 1-365, expiration_days 1-30, hasta 5 adjuntos). La
     * Cotización pasa de "solicitada" a "pendiente" y se notifica al Cliente
     * como una Cotización recibida (Requisito 5.4). A partir de ahí sigue el
     * ciclo normal de aceptación/rechazo/expiración.
     *
     * Reglas de acceso y estado:
     *  - Solo el Prestador objetivo (`provider_user_id`) puede responder; en
     *    otro caso se lanza QuoteNotAuthorizedException (HTTP 403).
     *  - Solo mientras la Cotización está "solicitada"; en otro caso se lanza
     *    QuoteNotActionableException::notSolicitada (HTTP 409).
     *
     * @param  array{
     *     service_description: string,
     *     items: array<int, array{description: string, unit_price: int|float|string, quantity: int}>,
     *     delivery_days: int,
     *     expiration_days: int
     * }  $data
     * @param  array<int, UploadedFile>  $attachments
     *
     * @throws QuoteNotAuthorizedException si el usuario no es el Prestador objetivo.
     * @throws QuoteNotActionableException si la Cotización no está "solicitada".
     */
    public function respondToDirectQuote(Quote $quote, User $provider, array $data, array $attachments = []): Quote
    {
        if ($quote->provider_user_id !== $provider->id) {
            throw new QuoteNotAuthorizedException(
                'No tienes permiso para responder esta solicitud de cotización.',
            );
        }

        if ($quote->status !== Quote::STATUS_SOLICITADA) {
            throw QuoteNotActionableException::notSolicitada($quote);
        }

        $items = $this->normalizeItems($data['items']);
        $totalPrice = $this->sumSubtotals($items);
        $expiresAt = now()->startOfDay()->addDays((int) $data['expiration_days']);

        $quote = DB::transaction(function () use ($quote, $data, $items, $totalPrice, $expiresAt, $attachments): Quote {
            $quote->fill([
                'service_description' => $data['service_description'],
                'total_price' => $totalPrice,
                'delivery_days' => (int) $data['delivery_days'],
                'expires_at' => $expiresAt,
                'status' => Quote::STATUS_PENDIENTE,
            ]);
            $quote->save();

            $quote->items()->createMany($items);

            foreach ($attachments as $attachment) {
                $quote->addMedia($attachment)
                    ->toMediaCollection(Quote::ATTACHMENTS_MEDIA_COLLECTION);
            }

            return $quote;
        });

        // Notifica al Cliente que ya tiene una Cotización pendiente (Req 5.4).
        $this->notifier->notifyClientOfNewQuote($quote);

        // Programa la expiración estándar de la Cotización ya respondida
        // (Requisito 10.4 remite al Requisito 6): ExpireQuoteJob con delay hasta
        // `expires_at`. Al llegar la fecha, si sigue "pendiente", pasa a
        // "expirada" y notifica a ambas partes.
        ExpireQuoteJob::dispatch($quote->id)->delay($expiresAt);

        return $quote->refresh();
    }

    /**
     * Comprueba que el usuario es el Cliente receptor (403) y que la Cotización
     * admite aceptación/rechazo por su estado (409), aplicando el caso especial
     * de la Cotización "expirada" (Requisito 6.4) con un mensaje específico.
     *
     * @throws QuoteNotAuthorizedException
     * @throws QuoteNotActionableException
     */
    private function assertActionable(Quote $quote, User $client): void
    {
        if ($quote->client_user_id !== $client->id) {
            throw new QuoteNotAuthorizedException();
        }

        if ($quote->status === Quote::STATUS_EXPIRADA) {
            throw QuoteNotActionableException::expired($quote);
        }

        if ($quote->status !== Quote::STATUS_PENDIENTE) {
            throw QuoteNotActionableException::notPending($quote);
        }
    }

    /**
     * Normaliza los ítems del payload calculando el subtotal de cada uno como
     * unit_price × quantity, redondeado a 2 decimales.
     *
     * @param  array<int, array{description: string, unit_price: int|float|string, quantity: int}>  $items
     * @return array<int, array{description: string, unit_price: float, quantity: int, subtotal: float}>
     */
    private function normalizeItems(array $items): array
    {
        return array_map(function (array $item): array {
            $unitPrice = round((float) $item['unit_price'], 2);
            $quantity = (int) $item['quantity'];

            return [
                'description' => $item['description'],
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => round($unitPrice * $quantity, 2),
            ];
        }, array_values($items));
    }

    /**
     * Suma los subtotales de los ítems normalizados para obtener el precio
     * total de la Cotización (Requisito 5.2).
     *
     * @param  array<int, array{subtotal: float}>  $items
     */
    private function sumSubtotals(array $items): float
    {
        return round(array_sum(array_column($items, 'subtotal')), 2);
    }
}

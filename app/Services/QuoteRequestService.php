<?php

namespace App\Services;

use App\Contracts\QuoteRequestNotifier;
use App\Exceptions\ProviderSelectionException;
use App\Exceptions\QuoteRequestApplicationException;
use App\Exceptions\QuoteRequestImageException;
use App\Exceptions\QuoteRequestNotEditableException;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestApplication;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de Solicitudes de Cotización (Gestor_Solicitudes del dominio).
 *
 * Cubre la tarea 7.1: creación, edición y cierre de Solicitudes_Cotización
 * (Requisitos 4.1, 4.2, 4.6, 4.7). La búsqueda/filtrado y la postulación de
 * prestadores (Requisito 4.3/4.4) pertenecen a la tarea 7.2. La selección de
 * ganador (Requisito 4.5) la añade la tarea 7.5 vía selectProvider().
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 7.1 / tarea 14.1)
 * -----------------------------------------------------------------------------
 * La notificación a los postulantes al editar/cerrar (Requisitos 4.6, 4.7) se
 * delega a través del seam App\Contracts\QuoteRequestNotifier, que hoy resuelve
 * a una implementación placeholder (LogQuoteRequestNotifier). La tarea 14.1
 * sustituirá el binding por un adaptador sobre NotificationService sin tocar
 * este servicio.
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 7.2 / tarea 13.1)
 * -----------------------------------------------------------------------------
 * El ciclo de vida del canal de Chat se delega de forma aditiva al Gestor_Chat
 * (App\Services\ChatService): apply() habilita un canal "pre_aceptacion"
 * (Requisito 11.2) y close() cierra los canales "pre_aceptacion" asociados
 * (Requisito 11.3). ChatService es idempotente, por lo que estos enganches no
 * alteran la semántica previa de postular/cerrar.
 * -----------------------------------------------------------------------------
 */
class QuoteRequestService
{
    public function __construct(
        private readonly QuoteRequestNotifier $notifier,
        private readonly ChatService $chatService,
    ) {}

    /**
     * Crea una nueva Solicitud_Cotización para el Cliente dado (Requisitos 4.1, 4.2).
     *
     * Los datos ya vienen validados por el Form Request (título ≤100,
     * descripción ≤2000, categoría existente, ubicación requerida, presupuesto
     * opcional). La solicitud se crea siempre en estado "abierta".
     *
     * La Ciudad (`city_id`) es la ubicación canónica; el Departamento y el País
     * se derivan de ella vía las relaciones del Catálogo_Ubicación y no se
     * persisten de forma redundante (Requisitos 4.10, 15.10). La `location` se
     * persiste como Dirección_Referencia opcional (Requisito 4.11).
     *
     * Las imágenes de referencia opcionales (hasta 5 archivos JPG/PNG/WEBP) se
     * adjuntan mediante la Media_Library dentro de la misma transacción, de modo
     * que un fallo al almacenarlas revierte también la creación de la Solicitud
     * (Requisitos 4.13, 4.14). Una Solicitud sin imágenes es válida.
     *
     * @param  array{service_category_id: int, title: string, description: string, city_id: int, location?: string|null, estimated_budget?: float|null}  $data
     * @param  array<int, UploadedFile>  $referenceImages
     */
    public function create(User $client, array $data, array $referenceImages = []): QuoteRequest
    {
        return DB::transaction(function () use ($client, $data, $referenceImages): QuoteRequest {
            $quoteRequest = QuoteRequest::create([
                'client_user_id' => $client->id,
                'service_category_id' => $data['service_category_id'],
                'title' => $data['title'],
                'description' => $data['description'],
                'city_id' => $data['city_id'],
                'location' => $data['location'] ?? null,
                'estimated_budget' => $data['estimated_budget'] ?? null,
                'status' => QuoteRequest::STATUS_ABIERTA,
            ]);

            $this->attachReferenceImages($quoteRequest, $referenceImages);

            // Refresca para descartar la relación `media` cargada (vacía) al
            // validar el tope, de modo que la respuesta incluya las imágenes.
            return $quoteRequest->refresh();
        });
    }

    /**
     * Edita una Solicitud_Cotización (Requisito 4.6).
     *
     * Solo se permite mientras la solicitud está en estado "abierta"; en otro
     * caso se lanza QuoteRequestNotEditableException (que el controlador
     * traduce a HTTP 409). Tras persistir los cambios, se notifica a todos los
     * Prestadores que hayan postulado.
     *
     * Las imágenes de referencia opcionales (hasta 5 archivos JPG/PNG/WEBP) se
     * adjuntan mediante la Media_Library dentro de la misma transacción, de modo
     * que un fallo al almacenarlas revierte también la edición de la Solicitud
     * (Requisitos 4.13, 4.14).
     *
     * @param  array<string, mixed>  $data  Campos editables ya validados.
     * @param  array<int, UploadedFile>  $referenceImages
     */
    public function update(QuoteRequest $quoteRequest, array $data, array $referenceImages = []): QuoteRequest
    {
        $this->assertOpen($quoteRequest);

        // `department_id` solo se usa para validar la pertenencia de la Ciudad
        // en el Form Request; no se persiste (se deriva de la Ciudad,
        // Requisito 15.10).
        $editable = array_intersect_key($data, array_flip([
            'service_category_id',
            'title',
            'description',
            'city_id',
            'location',
            'estimated_budget',
        ]));

        $quoteRequest = DB::transaction(function () use ($quoteRequest, $editable, $referenceImages): QuoteRequest {
            $quoteRequest->fill($editable);
            $quoteRequest->save();

            $this->attachReferenceImages($quoteRequest, $referenceImages);

            return $quoteRequest->refresh();
        });

        // Notifica a los postulantes del cambio (Requisito 4.6).
        $this->notifier->notifyApplicantsOfEdit($quoteRequest);

        return $quoteRequest;
    }

    /**
     * Cierra manualmente una Solicitud_Cotización (Requisito 4.7).
     *
     * Solo se permite mientras la solicitud está en estado "abierta"; en otro
     * caso se lanza QuoteRequestNotEditableException (HTTP 409). El estado pasa
     * a "cerrada" y se notifica a todos los Prestadores que hayan postulado.
     */
    public function close(QuoteRequest $quoteRequest): QuoteRequest
    {
        $this->assertOpen($quoteRequest);

        $quoteRequest = DB::transaction(function () use ($quoteRequest): QuoteRequest {
            $quoteRequest->status = QuoteRequest::STATUS_CERRADA;
            $quoteRequest->save();

            // Cierra los canales de Chat "pre_aceptacion" de las postulaciones
            // de la Solicitud cerrada sin aceptación, impidiendo nuevos mensajes
            // (Requisito 11.3). Enganche aditivo e idempotente vía el
            // Gestor_Chat (tarea 13.1).
            $this->chatService->closePreAcceptanceChannelsForRequest($quoteRequest);

            return $quoteRequest->refresh();
        });

        // Notifica a los postulantes del cierre (Requisito 4.7).
        $this->notifier->notifyApplicantsOfClosure($quoteRequest);

        return $quoteRequest;
    }

    /**
     * Selecciona al Prestador ganador de una Solicitud_Cotización (Requisito 4.5).
     *
     * Reglas de negocio:
     *  - La Solicitud debe estar en estado "abierta"; en otro caso se lanza
     *    ProviderSelectionException con estado 409 (conflicto de estado).
     *  - El Prestador indicado debe haber postulado a la Solicitud; en otro
     *    caso se lanza ProviderSelectionException con estado 422.
     *
     * Si todo es válido, el estado pasa a "en proceso" y se notifica al
     * Prestador seleccionado a través del seam QuoteRequestNotifier.
     *
     * @throws ProviderSelectionException
     */
    public function selectProvider(QuoteRequest $quoteRequest, int $providerUserId): QuoteRequest
    {
        if ($quoteRequest->status !== QuoteRequest::STATUS_ABIERTA) {
            throw ProviderSelectionException::notOpen($quoteRequest->status);
        }

        $hasApplied = $quoteRequest->applications()
            ->where('provider_user_id', $providerUserId)
            ->exists();

        if (! $hasApplied) {
            throw ProviderSelectionException::providerNotApplicant();
        }

        $quoteRequest = DB::transaction(function () use ($quoteRequest): QuoteRequest {
            $quoteRequest->status = QuoteRequest::STATUS_EN_PROCESO;
            $quoteRequest->save();

            return $quoteRequest->refresh();
        });

        // Notifica al Prestador seleccionado (Requisito 4.5).
        $this->notifier->notifyProviderSelected($quoteRequest, $providerUserId);

        return $quoteRequest;
    }

    /**
     * Listado público paginado de Solicitudes_Cotización con filtros (Requisito 4.3).
     *
     * Soporta filtrar por:
     *  - `category`      : slug de categoría de servicio.
     *  - `department_id` : id de Departamento; filtra por la Ciudad perteneciente a él.
     *  - `city_id`       : id de Ciudad; acota a esa Ciudad (tiene prioridad sobre department_id).
     *  - `budget_min`  : presupuesto estimado mínimo (incluye el límite).
     *  - `budget_max`  : presupuesto estimado máximo (incluye el límite).
     *
     * Las solicitudes sin presupuesto estimado (`estimated_budget` NULL) se
     * excluyen cuando se aplica cualquiera de los límites de presupuesto, ya
     * que no pueden satisfacer un rango numérico.
     *
     * Un resultado vacío NO es un error: el controlador responde 200 con un
     * mensaje indicando que no se encontraron solicitudes (Requisito 4.3).
     *
     * @param  array{category?: ?string, department_id?: ?int, city_id?: ?int, budget_min?: float|int|null, budget_max?: float|int|null}  $filters
     * @return LengthAwarePaginator<QuoteRequest>
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $category = $filters['category'] ?? null;
        $departmentId = $filters['department_id'] ?? null;
        $cityId = $filters['city_id'] ?? null;
        $budgetMin = $filters['budget_min'] ?? null;
        $budgetMax = $filters['budget_max'] ?? null;

        return QuoteRequest::query()
            ->with(['category:id,name,slug', 'client:id,name'])
            ->when($category !== null && $category !== '', function (Builder $query) use ($category): void {
                $query->whereHas('category', fn (Builder $q) => $q->where('slug', $category));
            })
            ->when($cityId !== null, function (Builder $query) use ($cityId): void {
                $query->where('city_id', $cityId);
            })
            ->when($cityId === null && $departmentId !== null, function (Builder $query) use ($departmentId): void {
                $query->whereHas('city', fn (Builder $q): Builder => $q->where('department_id', $departmentId));
            })
            ->when($budgetMin !== null, function (Builder $query) use ($budgetMin): void {
                $query->whereNotNull('estimated_budget')->where('estimated_budget', '>=', $budgetMin);
            })
            ->when($budgetMax !== null, function (Builder $query) use ($budgetMax): void {
                $query->whereNotNull('estimated_budget')->where('estimated_budget', '<=', $budgetMax);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Registra la postulación de un Prestador a una Solicitud_Cotización
     * (Requisitos 4.4, 4.8).
     *
     * Reglas de negocio:
     *  - La Solicitud debe estar en estado "abierta"; en otro caso se lanza
     *    QuoteRequestApplicationException::notOpen() (HTTP 409, Requisito 4.8).
     *  - Un mismo Prestador no puede postular dos veces a la misma Solicitud;
     *    en ese caso se lanza QuoteRequestApplicationException::duplicate()
     *    (HTTP 409, Requisito 4.4). La restricción única en
     *    `quote_request_applications` lo garantiza también a nivel de datos y
     *    actúa como defensa ante condiciones de carrera.
     *
     * Si la postulación se registra, se notifica al Cliente dueño a través del
     * seam QuoteRequestNotifier (Requisito 4.4).
     *
     * @throws QuoteRequestApplicationException
     */
    public function apply(QuoteRequest $quoteRequest, User $provider): QuoteRequestApplication
    {
        if ($quoteRequest->client_user_id === $provider->id) {
            throw QuoteRequestApplicationException::ownRequest();
        }

        if ($quoteRequest->status !== QuoteRequest::STATUS_ABIERTA) {
            throw QuoteRequestApplicationException::notOpen();
        }

        $alreadyApplied = $quoteRequest->applications()
            ->where('provider_user_id', $provider->id)
            ->exists();

        if ($alreadyApplied) {
            throw QuoteRequestApplicationException::duplicate();
        }

        try {
            $application = DB::transaction(function () use ($quoteRequest, $provider): QuoteRequestApplication {
                $application = $quoteRequest->applications()->create([
                    'provider_user_id' => $provider->id,
                    'applied_at' => now(),
                ]);

                // Habilita el canal de Chat "pre_aceptacion" entre el Prestador
                // que postula y el Cliente dueño de la Solicitud, para consultas
                // previas a la aceptación formal (Requisito 11.2). Enganche
                // aditivo e idempotente vía el Gestor_Chat (tarea 13.1).
                $this->chatService->createPreAcceptanceChannel($application);

                return $application;
            });
        } catch (QueryException $e) {
            // Defensa ante carreras: la restricción única bloquea el duplicado.
            if ($this->isUniqueConstraintViolation($e)) {
                throw QuoteRequestApplicationException::duplicate();
            }

            throw $e;
        }

        // Notifica al Cliente dueño de la Solicitud (Requisito 4.4).
        $this->notifier->notifyClientOfApplication($quoteRequest, $provider->id);

        return $application;
    }

    /**
     * Listado de postulaciones de una Solicitud_Cotización, para el Cliente
     * dueño (Requisito 4.4).
     *
     * Devuelve las postulaciones con el Prestador cargado, ordenadas de la más
     * reciente a la más antigua. El control de acceso (solo el Cliente dueño)
     * lo aplica el controlador antes de invocar este método.
     *
     * @return Collection<int, QuoteRequestApplication>
     */
    public function applicationsFor(QuoteRequest $quoteRequest)
    {
        return $quoteRequest->applications()
            ->with(['provider:id,name', 'provider.media'])
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Postulaciones del Prestador, más recientes primero. Incluye en cada
     * elemento la relación `my_quote` (última cotización del Prestador para la
     * solicitud) resuelta con una sola consulta para toda la página.
     */
    public function paginateApplicationsOf(User $provider, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        $page = QuoteRequestApplication::query()
            ->where('provider_user_id', $provider->id)
            ->with([
                'quoteRequest:id,title,status,client_user_id,service_category_id',
                'quoteRequest.category:id,name',
                'quoteRequest.client:id,name',
                'quoteRequest.client.media',
                'chatChannel:id,quote_request_application_id',
            ])
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $requestIds = $page->getCollection()->pluck('quote_request_id')->unique()->values();

        // Última cotización (mayor id) del Prestador por solicitud.
        $latestIds = $requestIds->isEmpty() ? collect() : \App\Models\Quote::query()
            ->where('provider_user_id', $provider->id)
            ->whereIn('quote_request_id', $requestIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('quote_request_id')
            ->pluck('id');

        $quotes = $latestIds->isEmpty()
            ? collect()
            : \App\Models\Quote::query()
                ->whereIn('id', $latestIds)
                ->get(['id', 'quote_request_id', 'status', 'total_price'])
                ->keyBy('quote_request_id');

        $page->getCollection()->each(function (QuoteRequestApplication $application) use ($quotes): void {
            $application->setRelation('myQuote', $quotes->get($application->quote_request_id));
        });

        return $page;
    }

    /**
     * Adjunta las imágenes de referencia subidas a la Solicitud_Cotización vía
     * la Media_Library (Requisitos 4.13, 4.14).
     *
     * Debe invocarse dentro de la transacción que persiste la Solicitud: si el
     * almacenamiento de algún archivo falla, la transacción se revierte y no se
     * persiste ni la Solicitud ni los adjuntos. Una lista vacía es válida (una
     * Solicitud sin imágenes de referencia es válida).
     *
     * @param  array<int, UploadedFile>  $referenceImages
     */
    private function attachReferenceImages(QuoteRequest $quoteRequest, array $referenceImages): void
    {
        if ($referenceImages !== []) {
            $existing = $quoteRequest->getMedia(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION)->count();

            if ($existing + count($referenceImages) > QuoteRequest::MAX_REFERENCE_IMAGES) {
                throw QuoteRequestImageException::limitReached(QuoteRequest::MAX_REFERENCE_IMAGES);
            }
        }

        foreach ($referenceImages as $image) {
            $quoteRequest->addMedia($image)
                ->toMediaCollection(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION);
        }
    }

    /**
     * Agrega imágenes de referencia a una Solicitud "abierta" (Requisito 4.16).
     * La guarda dueño-only (403) la aplica el controlador.
     *
     * @param  array<int, UploadedFile>  $referenceImages
     *
     * @throws QuoteRequestImageException 409 si no está "abierta"; 422 si se supera el tope.
     */
    public function addReferenceImages(QuoteRequest $quoteRequest, array $referenceImages): QuoteRequest
    {
        if ($quoteRequest->status !== QuoteRequest::STATUS_ABIERTA) {
            throw QuoteRequestImageException::notOpen($quoteRequest->status);
        }

        DB::transaction(function () use ($quoteRequest, $referenceImages): void {
            $this->attachReferenceImages($quoteRequest, $referenceImages);
        });

        return $quoteRequest->refresh();
    }

    /**
     * Elimina una imagen de referencia de una Solicitud "abierta" (Requisito 4.16).
     *
     * @throws QuoteRequestImageException 409 si no está "abierta"; 404 si la imagen no pertenece a la solicitud.
     */
    public function removeReferenceImage(QuoteRequest $quoteRequest, int $mediaId): QuoteRequest
    {
        if ($quoteRequest->status !== QuoteRequest::STATUS_ABIERTA) {
            throw QuoteRequestImageException::notOpen($quoteRequest->status);
        }

        $media = $quoteRequest->getMedia(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION)
            ->firstWhere('id', $mediaId);

        if ($media === null) {
            throw QuoteRequestImageException::notFound();
        }

        $media->delete();

        return $quoteRequest->refresh();
    }

    /**
     * Determina si una QueryException corresponde a una violación de
     * restricción única (código SQLSTATE 23000/23505), de forma independiente
     * del driver de base de datos.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }

    /**
     * Garantiza que la solicitud esté en estado "abierta" antes de editarla o
     * cerrarla (Requisitos 4.6, 4.7).
     *
     * @throws QuoteRequestNotEditableException si el estado no es "abierta".
     */
    private function assertOpen(QuoteRequest $quoteRequest): void
    {
        if ($quoteRequest->status !== QuoteRequest::STATUS_ABIERTA) {
            throw new QuoteRequestNotEditableException($quoteRequest->status);
        }
    }
}

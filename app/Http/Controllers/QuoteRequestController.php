<?php

namespace App\Http\Controllers;

use App\Exceptions\ProviderSelectionException;
use App\Exceptions\QuoteRequestApplicationException;
use App\Exceptions\QuoteRequestImageException;
use App\Exceptions\QuoteRequestNotEditableException;
use App\Http\Requests\AddReferenceImagesRequest;
use App\Http\Requests\IndexMyApplicationsRequest;
use App\Http\Requests\IndexQuoteRequestsRequest;
use App\Http\Requests\StoreQuoteRequestRequest;
use App\Http\Requests\UpdateQuoteRequestRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestApplication;
use App\Services\QuoteRequestService;
use App\Support\AvatarPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Controlador de Solicitudes de Cotización (/api/v1/quote-requests).
 *
 * Tarea 7.1 (Requisitos 4.1, 4.2, 4.6, 4.7):
 *   - GET    /quote-requests         Listado público (sin auth).
 *   - POST   /quote-requests         Crear solicitud (Cliente autenticado).
 *   - GET    /quote-requests/{id}    Detalle público (sin auth).
 *   - PUT    /quote-requests/{id}    Editar (Cliente dueño; solo "abierta").
 *   - POST   /quote-requests/{id}/close  Cerrar (Cliente dueño; solo "abierta").
 *
 * Las acciones de escritura están restringidas al Cliente dueño: la ruta exige
 * auth:sanctum y aquí se verifica la propiedad del recurso (403 en caso
 * contrario). Editar/cerrar una solicitud que no esté "abierta" es un error de
 * negocio que se traduce a HTTP 409.
 */
class QuoteRequestController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly QuoteRequestService $service) {}

    /**
     * GET /api/v1/quote-requests  (público)
     *
     * Listado público paginado de solicitudes con filtros por categoría,
     * ubicación y rango de presupuesto (Requisito 4.3). Si no hay resultados,
     * responde 200 con un mensaje indicándolo (no es un error).
     */
    public function index(IndexQuoteRequestsRequest $request): JsonResponse
    {
        $paginator = $this->service->paginate($request->filters(), $request->perPage());

        // Ruta pública: la miniatura del cliente solo se expone con token válido
        // (Req. 4.8). Para anónimos la clave se omite.
        if ($request->user('sanctum') !== null) {
            $paginator->getCollection()->load('client.media');
            $paginator->through(function (QuoteRequest $item): array {
                $data = $item->toArray();
                if (isset($data['client']) && is_array($data['client'])) {
                    unset($data['client']['media']);
                }

                return $data + AvatarPresenter::thumb($item->client, 'client_');
            });
        }

        $message = $paginator->total() === 0
            ? 'No se encontraron solicitudes de cotización con los filtros indicados.'
            : 'Listado de solicitudes de cotización.';

        return $this->paginated($paginator, $message);
    }

    /**
     * POST /api/v1/quote-requests  (auth:sanctum — Cliente)
     *
     * Crea una nueva Solicitud_Cotización en estado "abierta" (Requisitos 4.1, 4.2).
     */
    public function store(StoreQuoteRequestRequest $request): JsonResponse
    {
        $quoteRequest = $this->service->create(
            $request->user(),
            $request->validated(),
            $this->referenceImages($request),
        );

        return $this->created(
            data: ['quote_request' => $this->present($quoteRequest)],
            message: 'Solicitud de cotización creada correctamente.',
        );
    }

    /**
     * GET /api/v1/quote-requests/{quoteRequest}  (público)
     *
     * Detalle de una solicitud.
     */
    public function show(Request $request, QuoteRequest $quoteRequest): JsonResponse
    {
        return $this->ok(
            data: ['quote_request' => $this->present($quoteRequest, $request->user('sanctum') !== null)],
            message: 'Detalle de la solicitud de cotización.',
        );
    }

    /**
     * PUT /api/v1/quote-requests/{quoteRequest}  (auth:sanctum — Cliente dueño)
     *
     * Edita una solicitud "abierta" (Requisito 4.6). Notifica a los postulantes.
     */
    public function update(UpdateQuoteRequestRequest $request, QuoteRequest $quoteRequest): JsonResponse
    {
        if ($denied = $this->denyIfNotOwner($request, $quoteRequest)) {
            return $denied;
        }

        try {
            $quoteRequest = $this->service->update(
                $quoteRequest,
                $request->validated(),
                $this->referenceImages($request),
            );
        } catch (QuoteRequestNotEditableException $e) {
            return $this->error($e->getMessage(), status: 409);
        } catch (QuoteRequestImageException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: ['quote_request' => $this->present($quoteRequest)],
            message: 'Solicitud de cotización actualizada correctamente.',
        );
    }

    /**
     * POST /api/v1/quote-requests/{quoteRequest}/reference-images
     *   (auth:sanctum — Cliente dueño)
     *
     * Agrega imágenes de referencia a una solicitud "abierta" (Requisito 4.16).
     * 403 si no es el dueño; 409 si no está "abierta"; 422 si supera el tope de 5.
     */
    public function addReferenceImages(AddReferenceImagesRequest $request, QuoteRequest $quoteRequest): JsonResponse
    {
        if ($denied = $this->denyIfNotOwner($request, $quoteRequest)) {
            return $denied;
        }

        try {
            $quoteRequest = $this->service->addReferenceImages($quoteRequest, $this->referenceImages($request));
        } catch (QuoteRequestImageException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->created(
            data: ['quote_request' => $this->present($quoteRequest)],
            message: 'Imágenes de referencia agregadas correctamente.',
        );
    }

    /**
     * DELETE /api/v1/quote-requests/{quoteRequest}/reference-images/{mediaId}
     *   (auth:sanctum — Cliente dueño)
     *
     * Elimina una imagen de referencia de una solicitud "abierta" (Requisito 4.16).
     */
    public function removeReferenceImage(Request $request, QuoteRequest $quoteRequest, int $mediaId): JsonResponse
    {
        if ($denied = $this->denyIfNotOwner($request, $quoteRequest)) {
            return $denied;
        }

        try {
            $quoteRequest = $this->service->removeReferenceImage($quoteRequest, $mediaId);
        } catch (QuoteRequestImageException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: ['quote_request' => $this->present($quoteRequest)],
            message: 'Imagen de referencia eliminada correctamente.',
        );
    }

    /**
     * POST /api/v1/quote-requests/{quoteRequest}/close  (auth:sanctum — Cliente dueño)
     *
     * Cierra manualmente una solicitud "abierta" (Requisito 4.7). Notifica a los
     * postulantes.
     */
    public function close(UpdateQuoteRequestRequest $request, QuoteRequest $quoteRequest): JsonResponse
    {
        if ($denied = $this->denyIfNotOwner($request, $quoteRequest)) {
            return $denied;
        }

        try {
            $quoteRequest = $this->service->close($quoteRequest);
        } catch (QuoteRequestNotEditableException $e) {
            return $this->error($e->getMessage(), status: 409);
        }

        return $this->ok(
            data: ['quote_request' => $this->present($quoteRequest)],
            message: 'Solicitud de cotización cerrada correctamente.',
        );
    }

    /**
     * POST /api/v1/quote-requests/{quoteRequest}/select/{providerId}
     *   (auth:sanctum — Cliente dueño)
     *
     * Selecciona al Prestador ganador de la solicitud (Requisito 4.5): cambia
     * el estado a "en proceso" y notifica al Prestador seleccionado.
     *
     * Errores de negocio:
     *  - 403 si el solicitante no es el Cliente dueño.
     *  - 409 si la solicitud no está "abierta".
     *  - 422 si el Prestador indicado no ha postulado a la solicitud.
     */
    public function select(Request $request, QuoteRequest $quoteRequest, int $providerId): JsonResponse
    {
        if ($denied = $this->denyIfNotOwner($request, $quoteRequest)) {
            return $denied;
        }

        try {
            $quoteRequest = $this->service->selectProvider($quoteRequest, $providerId);
        } catch (ProviderSelectionException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: ['quote_request' => $this->present($quoteRequest)],
            message: 'Prestador seleccionado correctamente.',
        );
    }

    /**
     * POST /api/v1/quote-requests/{quoteRequest}/apply
     *   (auth:sanctum + role.provider — Prestador)
     *
     * Registra la postulación del Prestador autenticado a la solicitud
     * (Requisito 4.4) y notifica al Cliente dueño.
     *
     * Errores de negocio:
     *  - 409 si el Prestador ya ha postulado a esta solicitud (duplicada).
     *  - 409 si la solicitud no está "abierta" (Requisito 4.8).
     */
    public function apply(Request $request, QuoteRequest $quoteRequest): JsonResponse
    {
        try {
            $application = $this->service->apply($quoteRequest, $request->user());
        } catch (QuoteRequestApplicationException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->created(
            data: ['application' => $this->presentApplication($application)],
            message: 'Postulación registrada correctamente.',
        );
    }

    /**
     * GET /api/v1/quote-requests/my-applications  (auth:sanctum + role.provider)
     *
     * Postulaciones del Prestador autenticado, más recientes primero.
     */
    public function myApplications(IndexMyApplicationsRequest $request): JsonResponse
    {
        $paginator = $this->service
            ->paginateApplicationsOf($request->user(), $request->perPage())
            ->through(function (QuoteRequestApplication $application): array {
                $qr = $application->quoteRequest;
                $quote = $application->myQuote;

                return [
                    'id' => $application->id,
                    'applied_at' => $application->applied_at?->toIso8601String(),
                    'quote_request' => [
                        'id' => $qr?->id,
                        'title' => $qr?->title,
                        'status' => $qr?->status,
                        'client_name' => $qr?->client?->name,
                        ...AvatarPresenter::thumb($qr?->client, 'client_'),
                        'category_name' => $qr?->category?->name,
                    ],
                    'my_quote' => $quote === null ? null : [
                        'id' => $quote->id,
                        'status' => $quote->status,
                        'total_price' => (float) $quote->total_price,
                    ],
                    'chat_channel_id' => $application->chatChannel?->id,
                ];
            });

        return $this->paginated($paginator, 'Mis postulaciones.');
    }

    /**
     * GET /api/v1/quote-requests/{quoteRequest}/applications
     *   (auth:sanctum — Cliente dueño)
     *
     * Lista las postulaciones de una solicitud. Solo el Cliente dueño puede
     * consultarlas (403 en otro caso, Requisito 4.4).
     */
    public function applications(Request $request, QuoteRequest $quoteRequest): JsonResponse
    {
        if ($denied = $this->denyIfNotOwner($request, $quoteRequest)) {
            return $denied;
        }

        $applications = $this->service->applicationsFor($quoteRequest)
            ->map(fn ($application) => $this->presentApplication($application))
            ->all();

        return $this->ok(
            data: ['applications' => $applications],
            message: 'Listado de postulaciones de la solicitud.',
        );
    }

    /**
     * Extrae los archivos de imágenes de referencia subidos (campo multipart
     * `reference_images[]`) para pasarlos al servicio, que los adjunta vía la
     * Media_Library (Requisitos 4.13, 4.14). Devuelve una lista vacía cuando no
     * se envían archivos (una solicitud sin imágenes es válida).
     *
     * @return array<int, UploadedFile>
     */
    private function referenceImages(Request $request): array
    {
        $files = $request->file('reference_images', []);

        return is_array($files) ? array_values($files) : [$files];
    }

    /**
     * Rechaza con 403 si el usuario autenticado no es el Cliente dueño de la
     * solicitud. Devuelve null cuando sí es dueño (acción permitida).
     */
    private function denyIfNotOwner($request, QuoteRequest $quoteRequest): ?JsonResponse
    {
        if ($quoteRequest->client_user_id !== $request->user()->id) {
            return $this->error(
                'No tienes permiso para modificar esta solicitud de cotización.',
                status: 403,
            );
        }

        return null;
    }

    /**
     * Representación de una Postulación (QuoteRequestApplication) para la API.
     *
     * @return array<string, mixed>
     */
    private function presentApplication(QuoteRequestApplication $application): array
    {
        $application->loadMissing(['provider:id,name', 'provider.media']);

        return [
            'id' => $application->id,
            'quote_request_id' => $application->quote_request_id,
            'provider_user_id' => $application->provider_user_id,
            'provider_name' => $application->provider?->name,
            ...AvatarPresenter::thumb($application->provider, 'provider_'),
            'applied_at' => $application->applied_at?->toIso8601String(),
            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }

    /**
     * Representación de una Solicitud_Cotización para la API.
     *
     * @return array<string, mixed>
     */
    private function present(QuoteRequest $quoteRequest, bool $withAvatar = true): array
    {
        $quoteRequest->loadMissing([
            'category:id,name,slug',
            'client:id,name',
            'city.department.country',
        ]);

        if ($withAvatar) {
            $quoteRequest->loadMissing('client.media');
        }

        $city = $quoteRequest->city;
        $department = $city?->department;
        $country = $department?->country;

        return [
            'id' => $quoteRequest->id,
            'client_user_id' => $quoteRequest->client_user_id,
            'client_name' => $quoteRequest->client?->name,
            ...($withAvatar ? AvatarPresenter::thumb($quoteRequest->client, 'client_') : []),
            'service_category_id' => $quoteRequest->service_category_id,
            'category' => $quoteRequest->category === null ? null : [
                'id' => $quoteRequest->category->id,
                'name' => $quoteRequest->category->name,
                'slug' => $quoteRequest->category->slug,
            ],
            'title' => $quoteRequest->title,
            'description' => $quoteRequest->description,
            // Ubicación canónica: la Ciudad del Catálogo_Ubicación. El
            // Departamento y el País se derivan de ella (Requisito 15.10).
            'city_id' => $quoteRequest->city_id,
            'city' => $city === null ? null : [
                'id' => $city->id,
                'name' => $city->name,
                'department_id' => $city->department_id,
                'department' => $department === null ? null : [
                    'id' => $department->id,
                    'name' => $department->name,
                    'country_id' => $department->country_id,
                    'country' => $country === null ? null : [
                        'id' => $country->id,
                        'name' => $country->name,
                    ],
                ],
            ],
            // Dirección_Referencia opcional (texto libre).
            'location' => $quoteRequest->location,
            'estimated_budget' => $quoteRequest->estimated_budget !== null
                ? (float) $quoteRequest->estimated_budget
                : null,
            'status' => $quoteRequest->status,
            // Imágenes de referencia visibles para cualquier Prestador antes de
            // postular/cotizar (Requisito 4.15).
            'reference_images' => $quoteRequest
                ->getMedia(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION)
                ->map(fn ($media): array => [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                    'thumb_url' => $media->hasGeneratedConversion('thumb')
                        ? $media->getUrl('thumb')
                        : null,
                ])->values()->all(),
            'created_at' => $quoteRequest->created_at?->toIso8601String(),
            'updated_at' => $quoteRequest->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexProvidersRequest;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\HasApiResponses;
use App\Models\ProviderProfile;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;

/**
 * Controlador público de perfiles de Prestador (/api/v1/providers).
 *
 * Expone el Perfil_Prestador de forma pública (sin autenticación), según el
 * diseño de la API. Pertenece a la tarea 5.4.
 */
class ProviderProfileController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly ProfileService $profileService)
    {
    }

    /**
     * GET /api/v1/providers  (público) — tarea 5.7
     *
     * Listado público paginado de Prestadores con Perfil_Prestador activo,
     * ordenado por Índice_Cumplimiento descendente, con filtros por categoría,
     * ubicación y rango de calificación (1.0-5.0), y búsqueda parcial
     * case-insensitive por nombre/descripción (término de 2-100 caracteres).
     *
     * La validación de los parámetros de consulta (incluido el rango de
     * longitud del término de búsqueda, que retorna 422) la realiza
     * IndexProvidersRequest. Cuando ningún Prestador coincide, se retorna un
     * listado vacío con un mensaje informativo y HTTP 200 (NO es un error,
     * Requisito 13.6).
     *
     * La paginación es de 20 elementos por página por defecto e incluye en
     * `meta` el total de resultados y el número total de páginas
     * (`meta.total`, `meta.last_page`), Requisitos 13.1, 13.3.
     */
    public function index(IndexProvidersRequest $request): JsonResponse
    {
        $paginator = $this->profileService->searchProviders(
            $request->filters(),
            $request->perPage(),
        );

        // Transforma los modelos a la representación de listado conservando la
        // metadata de paginación del paginador original.
        $items = collect($paginator->items())
            ->map(fn (ProviderProfile $profile): array => $this->profileService->presentProviderListItem($profile))
            ->values()
            ->all();

        $message = $paginator->total() === 0
            ? 'No se encontraron prestadores que coincidan con los criterios de búsqueda.'
            : 'Listado de prestadores obtenido correctamente.';

        return ApiResponse::success(
            data: $items,
            message: $message,
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        );
    }

    /**
     * GET /api/v1/providers/{id}  (público)
     *
     * Retorna el Perfil_Prestador público: nombre, descripción profesional,
     * categorías, portafolio, calificación promedio ("Sin calificaciones" si
     * no hay), Índice_Cumplimiento ("Sin datos suficientes" si <3 trabajos
     * completados o sin fechas estimadas) y reseñas recibidas ordenadas por
     * fecha de creación descendente (Requisitos 3.1, 3.5, 3.6, 3.9, 9.2, 9.4).
     *
     * El `{id}` corresponde al identificador del `provider_profile`. Si no
     * existe o el perfil no está activo, se retorna 404 (no es público).
     */
    public function show(int $id): JsonResponse
    {
        $profile = ProviderProfile::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if ($profile === null) {
            return $this->error('Prestador no encontrado.', status: 404);
        }

        return $this->ok(
            data: ['provider' => $this->profileService->getPublicProfile($profile)],
            message: 'Perfil del prestador obtenido correctamente.',
        );
    }
}

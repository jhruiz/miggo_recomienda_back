<?php

namespace App\Services;

use App\Models\ProviderProfile;
use App\Models\Rating;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\AvatarPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de perfiles (Gestor_Perfiles del dominio).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tareas en paralelo 4.1 / 4.3)
 * -----------------------------------------------------------------------------
 * Esta clase pertenece a la tarea 4.1 (asignación de rol cliente al registrar
 * y activación del rol prestador). La AUTORIZACIÓN por rol activo (middleware /
 * Gate que rechaza acciones de Prestador con 403, Requisito 2.6) la implementa
 * la tarea 4.3 en un archivo aparte (middleware/Gate). Aquí NO se implementa
 * dicho control: los endpoints de prestador se protegen únicamente con
 * `auth:sanctum` y la tarea 4.3 añadirá sobre ellos la verificación del rol
 * `prestador`.
 *
 * El rol base `cliente` se asigna en AuthService::register() (Requisito 2.1);
 * esta clase solo lo referencia (ROLE_CLIENT) y no lo duplica.
 * -----------------------------------------------------------------------------
 */
class ProfileService
{
    /** Rol base asignado al registrar (lo asigna AuthService::register()). */
    public const ROLE_CLIENT = 'cliente';

    /** Rol que habilita el perfil de prestador. */
    public const ROLE_PROVIDER = 'prestador';

    /**
     * Mínimo de trabajos completados requeridos para exponer el
     * Índice_Cumplimiento como porcentaje (Requisito 9.4).
     */
    public const MIN_COMPLETED_JOBS_FOR_COMPLIANCE = 3;

    /** Texto mostrado cuando el Prestador aún no tiene calificaciones (Req. 3.9). */
    public const LABEL_NO_RATINGS = 'Sin calificaciones';

    /** Texto mostrado cuando no hay datos suficientes de cumplimiento (Req. 9.4/9.5). */
    public const LABEL_INSUFFICIENT_COMPLIANCE = 'Sin datos suficientes';

    public function __construct(private readonly PortfolioService $portfolioService) {}

    /**
     * Identidad plana del usuario con los campos de foto de perfil.
     *
     * @return array<string, mixed>
     */
    public function presentIdentity(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'roles' => $user->getRoleNames(),
        ] + AvatarPresenter::full($user);
    }

    /**
     * Actualiza los datos de identidad compartidos del usuario (Requisito 2.5).
     *
     * Estos datos (nombre, correo, etc.) pertenecen a la cuenta única y son
     * independientes de los roles activos del usuario.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->fill($data);
        $user->save();

        return $user->refresh();
    }

    /**
     * Activa el rol de Prestador para un Usuario (Requisitos 2.2, 2.3).
     *
     * Crea el `provider_profile` del usuario (is_active = true) y le asigna el
     * rol `prestador` SIN remover el rol `cliente`. Toda la escritura se
     * envuelve en una transacción de base de datos: si algo falla a mitad de
     * camino, se hace rollback y el usuario NO queda con el rol asignado
     * parcialmente ni con un perfil huérfano (Requisito 2.3).
     *
     * Es idempotente: si el usuario ya tiene el rol prestador y/o ya posee un
     * provider_profile, no se duplica nada y se retorna el perfil existente.
     */
    public function activateProviderRole(User $user): ProviderProfile
    {
        return DB::transaction(function () use ($user): ProviderProfile {
            // Crea el perfil solo si aún no existe (idempotencia). Si existe,
            // se garantiza que quede activo.
            $profile = ProviderProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['is_active' => true],
            );

            if (! $profile->is_active) {
                $profile->update(['is_active' => true]);
            }

            // Asigna el rol prestador sin tocar el rol cliente. assignRole()
            // de Spatie es idempotente (no duplica la relación si ya existe).
            if (! $user->hasRole(self::ROLE_PROVIDER)) {
                $user->assignRole(self::ROLE_PROVIDER);
            }

            return $profile;
        });
    }

    /**
     * Actualiza los datos del perfil de prestador (descripción, Ciudad del
     * Catálogo_Ubicación y Dirección_Referencia opcional).
     *
     * La Ciudad (`city_id`) es la ubicación canónica; el Departamento y el País
     * se derivan de ella sin almacenarse de forma redundante (Requisitos 3.10,
     * 15.10). La `location` se persiste como Dirección_Referencia opcional
     * (Requisito 3.11). El `department_id` que acompaña al formulario solo sirve
     * para validar la pertenencia de la Ciudad en el Form Request y NO se
     * persiste.
     *
     * Requiere que el usuario ya tenga un provider_profile (es decir, que haya
     * activado el rol prestador). La verificación del rol `prestador` activo a
     * nivel de autorización HTTP corresponde a la tarea 4.3.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProviderProfile(User $user, array $data): ProviderProfile
    {
        $profile = $user->providerProfile;

        // Solo campos persistibles del perfil: `department_id` queda fuera
        // (se deriva de la Ciudad, Requisito 15.10).
        $editable = array_intersect_key($data, array_flip([
            'professional_description',
            'city_id',
            'location',
        ]));

        $profile->fill($editable);
        $profile->save();

        return $profile->refresh();
    }

    /**
     * Representa la Ciudad canónica del Perfil_Prestador con su Departamento y
     * País derivados del Catálogo_Ubicación (Requisito 15.10). Devuelve null si
     * el perfil aún no tiene Ciudad asignada.
     *
     * @return array<string, mixed>|null
     */
    public function presentCity(ProviderProfile $profile): ?array
    {
        $profile->loadMissing('city.department.country');

        $city = $profile->city;

        if ($city === null) {
            return null;
        }

        $department = $city->department;
        $country = $department?->country;

        return [
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
        ];
    }

    /**
     * Calcula la calificación promedio de un Prestador (Requisito 3.6).
     *
     * Es el promedio aritmético de las puntuaciones (`score`) de todas las
     * Calificaciones recibidas por el Usuario del Prestador, redondeado a un
     * decimal. Si el Prestador no ha recibido ninguna Calificación, retorna
     * `null` (que la capa de presentación muestra como "Sin calificaciones",
     * Requisito 3.9).
     *
     * El valor calculado se cachea en `provider_profiles.average_rating`
     * (campo cacheado según el diseño) para evitar recálculos en listados.
     */
    public function calculateAverageRating(ProviderProfile $profile): ?float
    {
        $query = Rating::query()->where('rated_user_id', $profile->user_id);

        $count = (clone $query)->count();

        if ($count === 0) {
            // Mantiene el cache consistente: sin calificaciones => null.
            if ($profile->average_rating !== null) {
                $profile->forceFill(['average_rating' => null])->save();
            }

            return null;
        }

        $average = round((float) (clone $query)->avg('score'), 1);

        // Refresca el valor cacheado en el perfil.
        $profile->forceFill(['average_rating' => $average])->save();

        return $average;
    }

    /**
     * Arma la representación pública del Perfil_Prestador (Requisito 3.1).
     *
     * Incluye: nombre, descripción profesional, categorías de servicio,
     * portafolio, calificación promedio ("Sin calificaciones" si no hay),
     * Índice_Cumplimiento ("Sin datos suficientes" con <3 trabajos completados
     * o sin fechas estimadas) y reseñas recibidas ordenadas por fecha de
     * creación descendente (Requisitos 3.1, 3.5, 3.6, 3.9, 9.2, 9.4).
     *
     * @return array<string, mixed>
     */
    public function getPublicProfile(ProviderProfile $profile): array
    {
        $profile->loadMissing(['user.media', 'categories', 'city.department.country']);

        $averageRating = $this->calculateAverageRating($profile);

        $reviews = Rating::query()
            ->where('rated_user_id', $profile->user_id)
            ->where('is_visible', true)
            ->with(['rater:id,name', 'rater.media'])
            ->orderByDesc('created_at')
            ->get();

        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'name' => $profile->user?->name,
            ...AvatarPresenter::full($profile->user),
            'professional_description' => $profile->professional_description,
            // Ubicación canónica (Ciudad) con Departamento y País derivados
            // (Requisito 15.10); `location` es la Dirección_Referencia opcional.
            'city_id' => $profile->city_id,
            'city' => $this->presentCity($profile),
            'location' => $profile->location,
            'is_active' => $profile->is_active,
            'categories' => $profile->categories->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ])->values()->all(),
            'portfolios' => $this->portfolioService->presentPublic($profile),
            // Promedio: valor numérico redondeado o la etiqueta "Sin calificaciones".
            'average_rating' => $averageRating ?? self::LABEL_NO_RATINGS,
            // Índice de cumplimiento: entero o "Sin datos suficientes".
            'compliance_index' => $this->presentComplianceIndex($profile),
            'completed_jobs_count' => (int) $profile->completed_jobs_count,
            'reviews' => $reviews->map(fn (Rating $rating): array => [
                'id' => $rating->id,
                'score' => $rating->score,
                'comment' => $rating->comment,
                'rater_name' => $rating->rater?->name,
            ] + AvatarPresenter::thumb($rating->rater, 'rater_') + [
                'created_at' => $rating->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Presenta el Índice_Cumplimiento para el perfil público (Requisitos 9.2, 9.4, 9.5).
     *
     * Retorna el porcentaje entero cacheado solo cuando el Prestador tiene al
     * menos 3 trabajos completados Y existe un `compliance_index` calculado
     * (es decir, al menos un trabajo con fecha estimada registrada). En
     * cualquier otro caso retorna "Sin datos suficientes".
     *
     * @return int|string
     */
    public function presentComplianceIndex(ProviderProfile $profile): int|string
    {
        $completed = (int) $profile->completed_jobs_count;

        if ($completed < self::MIN_COMPLETED_JOBS_FOR_COMPLIANCE || $profile->compliance_index === null) {
            return self::LABEL_INSUFFICIENT_COMPLIANCE;
        }

        return (int) $profile->compliance_index;
    }

    /**
     * Listado y búsqueda pública paginada de Prestadores (Requisito 13).
     *
     * Expone únicamente perfiles con el rol activo (`is_active = true`,
     * Requisito 13.1) y aplica, de forma acumulativa, los filtros recibidos
     * (Requisitos 13.2, 13.4):
     *
     * - `category`   : slug de categoría de servicio ofrecida por el Prestador.
     * - `department_id` / `city_id` : ubicación del Catálogo_Ubicación (la Ciudad acota; el Departamento incluye sus Ciudades).
     * - `rating_min` / `rating_max` : rango de calificación promedio (1.0-5.0).
     *   Los perfiles sin calificación promedio (`average_rating = null`) quedan
     *   fuera cuando se aplica cualquiera de estos límites.
     * - `search`     : coincidencia parcial case-insensitive sobre el nombre del
     *   Usuario o la descripción profesional del Prestador.
     *
     * El resultado se ordena por Índice_Cumplimiento descendente dejando los
     * perfiles sin datos suficientes al final (Requisito 13.1) y se pagina a
     * `$perPage` elementos por página con `meta.total` y `meta.last_page`
     * provistos por el envelope de respuesta (Requisitos 13.1, 13.3).
     *
     * La validación de longitud del término de búsqueda (2-100 caracteres,
     * Requisito 13.5) se realiza en IndexProvidersRequest antes de llamar a
     * este método; aquí se asume ya validado.
     *
     * @param  array{category?: ?string, department_id?: ?int, city_id?: ?int, rating_min?: ?float, rating_max?: ?float, search?: ?string}  $filters
     * @return LengthAwarePaginator<ProviderProfile>
     */
    public function searchProviders(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $category = $filters['category'] ?? null;
        $departmentId = $filters['department_id'] ?? null;
        $cityId = $filters['city_id'] ?? null;
        $ratingMin = $filters['rating_min'] ?? null;
        $ratingMax = $filters['rating_max'] ?? null;
        $search = $filters['search'] ?? null;

        $query = ProviderProfile::query()
            ->where('is_active', true)
            ->with(['user:id,name', 'user.media', 'categories:id,name,slug', 'city.department.country']);

        // Filtro por categoría de servicio (slug).
        if ($category !== null) {
            $query->whereHas('categories', function (Builder $categoryQuery) use ($category): void {
                $categoryQuery->where('service_categories.slug', $category);
            });
        }

        // Filtro por ubicación del Catálogo_Ubicación (Requisitos 13.8, 13.9).
        // La Ciudad tiene prioridad; si solo se indica el Departamento, se
        // incluyen los prestadores cuya Ciudad pertenece a ese Departamento.
        if ($cityId !== null) {
            $query->where('city_id', $cityId);
        } elseif ($departmentId !== null) {
            $query->whereHas('city', fn (Builder $q): Builder => $q->where('department_id', $departmentId));
        }

        // Rango de calificación promedio. Un perfil sin average_rating queda
        // excluido en cuanto se aplica cualquiera de los límites.
        if ($ratingMin !== null) {
            $query->whereNotNull('average_rating')->where('average_rating', '>=', $ratingMin);
        }

        if ($ratingMax !== null) {
            $query->whereNotNull('average_rating')->where('average_rating', '<=', $ratingMax);
        }

        // Búsqueda por nombre del usuario o descripción profesional
        // (coincidencia parcial, case-insensitive).
        if ($search !== null) {
            $needle = '%'.mb_strtolower($search).'%';

            $query->where(function (Builder $searchQuery) use ($needle): void {
                $searchQuery
                    ->whereRaw('LOWER(professional_description) LIKE ?', [$needle])
                    ->orWhereHas('user', function (Builder $userQuery) use ($needle): void {
                        $userQuery->whereRaw('LOWER(name) LIKE ?', [$needle]);
                    });
            });
        }

        // Orden por Índice_Cumplimiento descendente, dejando los perfiles sin
        // valor (NULL) al final, y desempatando por calificación promedio.
        $query
            ->orderByRaw('compliance_index IS NULL')
            ->orderByDesc('compliance_index')
            ->orderByDesc('average_rating')
            ->orderBy('id');

        return $query->paginate($perPage);
    }

    /**
     * Representación resumida de un Prestador para el listado público
     * (Requisito 13). Reutiliza las mismas etiquetas que el perfil público
     * para la calificación promedio y el Índice_Cumplimiento.
     *
     * @return array<string, mixed>
     */
    public function presentProviderListItem(ProviderProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'name' => $profile->user?->name,
            ...AvatarPresenter::thumb($profile->user),
            'professional_description' => $profile->professional_description,
            'city_id' => $profile->city_id,
            'city' => $this->presentCity($profile),
            'location' => $profile->location,
            'categories' => $profile->categories->map(fn (ServiceCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ])->values()->all(),
            'average_rating' => $profile->average_rating !== null
                ? (float) $profile->average_rating
                : self::LABEL_NO_RATINGS,
            'compliance_index' => $this->presentComplianceIndex($profile),
            'completed_jobs_count' => (int) $profile->completed_jobs_count,
        ];
    }
}

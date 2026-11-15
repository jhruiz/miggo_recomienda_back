<?php

namespace App\Http\Controllers;

use App\Exceptions\AvatarException;
use App\Http\Requests\UpdateAvatarRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\AvatarService;
use App\Support\AvatarPresenter;
use App\Http\Requests\UpdateProviderProfileRequest;
use App\Http\Responses\HasApiResponses;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de perfiles (/api/v1/profile).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tareas en paralelo 4.1 / 4.3)
 * -----------------------------------------------------------------------------
 * Este controlador pertenece a la tarea 4.1. Todos sus endpoints se protegen
 * con `auth:sanctum`. La AUTORIZACIÓN por rol `prestador` activo (Requisito
 * 2.6) la añade la tarea 4.3 mediante un middleware/Gate aplicado sobre los
 * endpoints de prestador (`/profile/provider` y `/profile/provider/activate`
 * según corresponda). Aquí NO se implementa esa verificación de rol.
 * -----------------------------------------------------------------------------
 */
class ProfileController extends Controller
{
    use HasApiResponses;

    public function __construct(
        private readonly ProfileService $profileService,
        private readonly AvatarService $avatarService,
    ) {
    }

    /**
     * PUT /api/v1/profile  (auth:sanctum)
     *
     * Actualiza los datos de identidad compartidos del usuario autenticado
     * (nombre, correo). Estos datos son únicos para la cuenta e independientes
     * de los roles activos (Requisito 2.5).
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profileService->updateProfile(
            $request->user(),
            $request->validated(),
        );

        return $this->ok(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ] + AvatarPresenter::full($user),
            ],
            message: 'Perfil actualizado correctamente.',
        );
    }

    /**
     * POST /api/v1/profile/avatar  (auth:sanctum, cualquier rol)
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        try {
            $user = $this->avatarService->replace($request->user(), $request->file('avatar'));
        } catch (AvatarException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: $this->profileService->presentIdentity($user),
            message: 'Foto de perfil actualizada correctamente.',
        );
    }

    /**
     * DELETE /api/v1/profile/avatar  (auth:sanctum, cualquier rol)
     */
    public function destroyAvatar(Request $request): JsonResponse
    {
        try {
            $user = $this->avatarService->remove($request->user());
        } catch (AvatarException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->ok(
            data: $this->profileService->presentIdentity($user),
            message: 'Foto de perfil eliminada correctamente.',
        );
    }

    /**
     * POST /api/v1/profile/provider/activate  (auth:sanctum)
     *
     * Activa el rol de Prestador del usuario autenticado: crea su
     * provider_profile (is_active = true) y le asigna el rol `prestador` sin
     * remover el rol `cliente`, dentro de una transacción (Requisitos 2.2, 2.3).
     * Es idempotente: si el usuario ya es prestador, retorna el perfil existente.
     */
    public function activateProvider(Request $request): JsonResponse
    {
        $user = $request->user();

        $profile = $this->profileService->activateProviderRole($user);

        return $this->ok(
            data: [
                'provider_profile' => [
                    'id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'professional_description' => $profile->professional_description,
                    'city_id' => $profile->city_id,
                    'city' => $this->profileService->presentCity($profile),
                    'location' => $profile->location,
                    'is_active' => $profile->is_active,
                ],
                'roles' => $user->fresh()->getRoleNames(),
            ],
            message: 'Rol de prestador activado correctamente.',
        );
    }

    /**
     * PUT /api/v1/profile/provider  (auth:sanctum)
     *
     * Actualiza los datos del perfil de prestador (descripción profesional,
     * ubicación) del usuario autenticado.
     *
     * Si el usuario aún no ha activado el rol prestador (no tiene
     * provider_profile), se retorna un error 409 indicándolo. La verificación
     * del rol `prestador` activo a nivel de autorización corresponde a 4.3.
     */
    public function updateProvider(UpdateProviderProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->providerProfile === null) {
            return $this->error(
                'Debes activar el rol de prestador antes de editar tu perfil de prestador.',
                status: 409,
            );
        }

        $profile = $this->profileService->updateProviderProfile(
            $user,
            $request->validated(),
        );

        return $this->ok(
            data: [
                'provider_profile' => [
                    'id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'professional_description' => $profile->professional_description,
                    // Ubicación canónica (Ciudad) con Departamento/País
                    // derivados (Requisito 15.10) y Dirección_Referencia.
                    'city_id' => $profile->city_id,
                    'city' => $this->profileService->presentCity($profile),
                    'location' => $profile->location,
                    'is_active' => $profile->is_active,
                ],
            ],
            message: 'Perfil de prestador actualizado correctamente.',
        );
    }
}

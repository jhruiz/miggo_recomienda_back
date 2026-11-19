<?php

namespace App\Http\Controllers;

use App\Exceptions\JobPhotoLimitExceededException;
use App\Exceptions\JobPhotoNotAllowedException;
use App\Http\Requests\StoreJobPhotosRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\Job;
use App\Models\JobPhoto;
use App\Services\JobPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Controlador de Fotos_Trabajo (/api/v1/jobs/{job}/photos).
 *
 * Orden de comprobaciones: 401 (middleware), 404 Trabajo, 403 Gate
 * `manage-job-photos`, 404 foto ajena, 422 validación, 409 estado no admitido.
 * Por eso el Trabajo y la foto se resuelven a mano (sin route-model binding) y
 * el Form Request se resuelve después de la autorización.
 */
class JobPhotoController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly JobPhotoService $photoService) {}

    /**
     * POST /api/v1/jobs/{job}/photos
     */
    public function store(Request $request, int $job): JsonResponse
    {
        $found = Job::find($job);

        if ($found === null) {
            return $this->jobNotFound();
        }

        if (Gate::denies('manage-job-photos', $found)) {
            return $this->forbidden();
        }

        // Resolver el Form Request valida aquí (422), tras 404 y 403.
        $validated = app(StoreJobPhotosRequest::class);

        try {
            $this->photoService->assertCanUpload($found);
            $this->photoService->addPhotos($found, $validated->file('photos', []));
        } catch (JobPhotoNotAllowedException|JobPhotoLimitExceededException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->created(
            data: ['photos' => $this->photoService->presentOwner($found)],
            message: 'Fotos agregadas al trabajo correctamente.',
        );
    }

    /**
     * DELETE /api/v1/jobs/{job}/photos/{photo}
     */
    public function destroy(Request $request, int $job, int $photo): JsonResponse
    {
        $found = Job::find($job);

        if ($found === null) {
            return $this->jobNotFound();
        }

        if (Gate::denies('manage-job-photos', $found)) {
            return $this->forbidden();
        }

        $jobPhoto = JobPhoto::where('job_id', $found->getKey())->find($photo);

        if ($jobPhoto === null) {
            return $this->error('La foto no existe o no pertenece a este trabajo.', status: 404);
        }

        $this->photoService->removePhoto($found, $jobPhoto);

        return $this->ok(
            data: ['photos' => $this->photoService->presentOwner($found)],
            message: 'Foto eliminada del trabajo correctamente.',
        );
    }

    private function jobNotFound(): JsonResponse
    {
        return $this->error('El trabajo no existe.', status: 404);
    }

    private function forbidden(): JsonResponse
    {
        return $this->error('Solo el prestador del trabajo puede gestionar sus fotos.', status: 403);
    }
}

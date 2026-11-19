<?php

namespace App\Services;

use App\Exceptions\JobPhotoLimitExceededException;
use App\Exceptions\JobPhotoNotAllowedException;
use App\Models\Job;
use App\Models\JobPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Servicio de Fotos_Trabajo (fotos de resultado de un Trabajo).
 */
final class JobPhotoService
{
    public const MAX_PHOTOS = 5;

    public const MEDIA_COLLECTION = 'job_photo';

    /**
     * Subir solo se admite con el Trabajo cerrado por el Prestador (en progreso
     * con `actual_completion_date`) o completado. Quitar no tiene esta restricción (DD18).
     *
     * @throws JobPhotoNotAllowedException
     */
    public function assertCanUpload(Job $job): void
    {
        $closedByProvider = $job->status === Job::STATUS_EN_PROGRESO && $job->actual_completion_date !== null;

        if (! $closedByProvider && $job->status !== Job::STATUS_COMPLETADO) {
            throw new JobPhotoNotAllowedException;
        }
    }

    /**
     * Crea de 1 a 5 fotos en una sola transacción (todo o nada). Si falla algo,
     * se borran los archivos ya escritos para no dejar huérfanos. Puede llamarse
     * dentro de una transacción mayor (cierre del Trabajo); el estado del Trabajo
     * se valida con assertCanUpload() por quien llama.
     *
     * @param  list<UploadedFile>  $files
     * @return list<JobPhoto>
     *
     * @throws JobPhotoLimitExceededException
     * @throws ValidationException
     */
    public function addPhotos(Job $job, array $files): array
    {
        $files = array_values($files);

        if ($files === []) {
            return [];
        }

        /** @var list<Media> $written */
        $written = [];

        try {
            return DB::transaction(function () use ($job, $files, &$written): array {
                Job::whereKey($job->getKey())->lockForUpdate()->first();

                $current = JobPhoto::where('job_id', $job->getKey())->count();

                if ($current + count($files) > self::MAX_PHOTOS) {
                    throw new JobPhotoLimitExceededException(self::MAX_PHOTOS, $current);
                }

                $photos = [];

                foreach ($files as $index => $file) {
                    try {
                        /** @var JobPhoto $photo */
                        $photo = $job->photos()->create();
                        $media = $photo->addMedia($file)->toMediaCollection(self::MEDIA_COLLECTION);
                        $written[] = $media;
                    } catch (FileUnacceptableForCollection|FileIsTooBig $e) {
                        throw ValidationException::withMessages(["photos.{$index}" => $e->getMessage()]);
                    }

                    $photos[] = $photo->refresh();
                }

                $job->unsetRelation('photos');

                return $photos;
            });
        } catch (Throwable $e) {
            $this->discardFiles($written);

            throw $e;
        }
    }

    /**
     * Borra la foto con su Original y conversiones. Si falla el borrado de
     * archivos, la transacción conserva el registro para poder reintentar.
     *
     * @throws Throwable
     */
    public function removePhoto(Job $job, JobPhoto $photo): void
    {
        try {
            DB::transaction(fn (): ?bool => $photo->delete());
        } catch (Throwable $e) {
            Log::error('No se pudo borrar la foto del trabajo.', [
                'job_photo_id' => $photo->getKey(),
                'job_id' => $job->getKey(),
                'exception' => $e,
            ]);

            throw $e;
        }

        $job->unsetRelation('photos');
    }

    /**
     * Lista de fotos para el Prestador: `thumb_url` si la conversión existe,
     * siempre `original_url` como alternativa.
     *
     * @return list<array<string, mixed>>
     */
    public function presentOwner(Job $job): array
    {
        $photos = $job->photos()->with('media')->get();

        return $photos->map(function (JobPhoto $photo): array {
            $media = $photo->getFirstMedia(self::MEDIA_COLLECTION);

            return [
                'id' => $photo->id,
                'original_url' => $media?->getUrl(),
                'thumb_url' => $media?->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
                'medium_url' => $media?->hasGeneratedConversion('medium') ? $media->getUrl('medium') : null,
                'conversions_ready' => $photo->conversions_ready_at !== null,
                'created_at' => $photo->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  list<Media>  $written
     */
    private function discardFiles(array $written): void
    {
        foreach ($written as $media) {
            try {
                Storage::disk($media->disk)->deleteDirectory(dirname($media->getPathRelativeToRoot()));
            } catch (Throwable $e) {
                Log::error('No se pudo limpiar un archivo huérfano de foto de trabajo.', [
                    'media_id' => $media->getKey(),
                    'exception' => $e,
                ]);
            }
        }
    }
}

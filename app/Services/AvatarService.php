<?php

namespace App\Services;

use App\Exceptions\AvatarException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use Spatie\Image\Exceptions\UnsupportedImageFormat;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Gestión de la Foto_Perfil de un Usuario (Requisitos 1.x, 2.x, 7.4).
 *
 * La colección `avatar` es de archivo único: Spatie borra el medio anterior
 * solo después de guardar el nuevo, por lo que un fallo deja intacta la foto vigente.
 */
final class AvatarService
{
    public const MAX_KB = 5120;

    private const INVALID_FILE_MESSAGE = 'El archivo está dañado o no es una imagen legible.';

    public function replace(User $user, UploadedFile $file): User
    {
        $extension = $this->extensionFor($file);

        try {
            DB::transaction(function () use ($user, $file, $extension): void {
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                $previousIds = $locked->media()
                    ->where('collection_name', User::AVATAR_COLLECTION)
                    ->pluck('id')
                    ->all();

                try {
                    $locked->addMedia($file)
                        ->usingName('avatar')
                        ->usingFileName(Str::uuid()->toString().'.'.$extension)
                        ->toMediaCollection(User::AVATAR_COLLECTION);
                } catch (Throwable $e) {
                    // El rollback revierte solo la base de datos: se borran los
                    // medios nuevos (archivos + fila) para no dejar huérfanos.
                    $this->deleteNewMedia($locked, $previousIds);

                    throw $e;
                }
            });
        } catch (FileUnacceptableForCollection|FileIsTooBig $e) {
            throw ValidationException::withMessages(['avatar' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($this->isDecodeFailure($e)) {
                throw ValidationException::withMessages(['avatar' => self::INVALID_FILE_MESSAGE]);
            }

            report($e);

            throw AvatarException::storeFailed();
        }

        return $this->fresh($user);
    }

    public function remove(User $user): User
    {
        try {
            DB::transaction(function () use ($user): void {
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $locked->clearMediaCollection(User::AVATAR_COLLECTION);
            });
        } catch (Throwable $e) {
            report($e);

            throw AvatarException::deleteFailed();
        }

        return $this->fresh($user);
    }

    /** @param  array<int, int>  $previousIds */
    private function deleteNewMedia(User $user, array $previousIds): void
    {
        try {
            Media::query()
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->getKey())
                ->where('collection_name', User::AVATAR_COLLECTION)
                ->whereNotIn('id', $previousIds)
                ->get()
                ->each(fn (Media $media) => $media->delete());
        } catch (Throwable $cleanup) {
            report($cleanup);
        }
    }

    /** Extensión derivada del contenido, normalizada a jpg|png|webp. */
    private function extensionFor(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->guessExtension());

        return match ($ext) {
            'jpg', 'jpeg', 'jpe' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => match ($file->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            },
        };
    }

    private function isDecodeFailure(Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof CouldNotLoadImage || $t instanceof UnsupportedImageFormat) {
                return true;
            }
        }

        return false;
    }

    private function fresh(User $user): User
    {
        $user->unsetRelation('media');

        return $user->load('media');
    }
}

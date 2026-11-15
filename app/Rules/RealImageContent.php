<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Verifica por contenido (getimagesize) que el archivo sea una imagen
 * JPEG, PNG o WEBP real, sin fiarse de la extensión ni del tipo declarado.
 */
class RealImageContent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'Tipo de archivo no permitido. Solo se aceptan imágenes JPG, PNG o WEBP.';

        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail($message);

            return;
        }

        $info = @getimagesize($value->getRealPath());

        if ($info === false || ! in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $fail($message);
        }
    }
}

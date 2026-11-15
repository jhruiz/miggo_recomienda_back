<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Falla si el archivo subido pesa 0 bytes (Requisito 1.4).
 */
class NonEmptyUploadedFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && $value->isValid() && ($value->getSize() ?: 0) === 0) {
            $fail('Debes seleccionar una imagen.');
        }
    }
}

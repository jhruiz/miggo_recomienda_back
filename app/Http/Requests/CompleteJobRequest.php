<?php

namespace App\Http\Requests;

use App\Services\JobPhotoService;
use App\Support\ImageUploadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para cerrar un Trabajo (POST /api/v1/jobs/{job}/complete).
 *
 * `photos` es opcional (0 a 5 Fotos_Trabajo). Sin fotos, el contrato no cambia.
 * La autorización por Prestador la aplica el controlador.
 */
class CompleteJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photos' => ['sometimes', 'nullable', 'array', 'max:'.JobPhotoService::MAX_PHOTOS],
            'photos.*' => array_values(array_filter(
                ImageUploadRules::rules(),
                fn (string $rule): bool => $rule !== 'required',
            )),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...ImageUploadRules::messages('photos.*'),
            'photos.array' => 'Las fotos deben enviarse como una lista.',
            'photos.max' => 'Puedes adjuntar como máximo '.JobPhotoService::MAX_PHOTOS.' fotos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'photos' => 'fotos',
        ];
    }
}

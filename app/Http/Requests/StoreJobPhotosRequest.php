<?php

namespace App\Http\Requests;

use App\Services\JobPhotoService;
use App\Support\ImageUploadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para añadir Fotos_Trabajo (POST /api/v1/jobs/{job}/photos):
 * de 1 a 5 imágenes por petición. La autorización la aplica el controlador.
 */
class StoreJobPhotosRequest extends FormRequest
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
            'photos' => ['required', 'array', 'min:1', 'max:'.JobPhotoService::MAX_PHOTOS],
            'photos.*' => ImageUploadRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...ImageUploadRules::messages('photos.*'),
            'photos.required' => 'Debes adjuntar al menos una foto.',
            'photos.array' => 'Las fotos deben enviarse como una lista.',
            'photos.min' => 'Debes adjuntar al menos una foto.',
            'photos.max' => 'Puedes adjuntar como máximo '.JobPhotoService::MAX_PHOTOS.' fotos por solicitud.',
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

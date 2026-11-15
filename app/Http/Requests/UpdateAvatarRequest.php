<?php

namespace App\Http\Requests;

use App\Rules\NonEmptyUploadedFile;
use App\Rules\RealImageContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para POST /api/v1/profile/avatar.
 * Solo se usa validated()['avatar']; el servicio opera siempre sobre
 * $request->user(), por lo que ningún user_id/id del cuerpo influye.
 */
class UpdateAvatarRequest extends FormRequest
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
            'avatar' => [
                'bail',
                'required',
                'file',
                new NonEmptyUploadedFile,
                'mimes:jpg,jpeg,png,webp',
                new RealImageContent,
                'max:5120',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Debes seleccionar una imagen.',
            'avatar.file' => 'No se pudo subir la imagen. Verifica que no supere 5 MB.',
            'avatar.uploaded' => 'No se pudo subir la imagen. Verifica que no supere 5 MB.',
            'avatar.mimes' => 'Tipo de archivo no permitido. Solo se aceptan imágenes JPG, PNG o WEBP.',
            'avatar.max' => 'El tamaño del archivo excede el máximo permitido de 5 MB.',
        ];
    }
}

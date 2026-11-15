<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para agregar imágenes de referencia a una Solicitud abierta
 * (POST /api/v1/quote-requests/{id}/reference-images, Requisito 4.16).
 * Al menos 1 y hasta 5 archivos JPG/PNG/WEBP de ≤10 MB. El tope acumulado
 * (contando las ya adjuntas) lo valida el servicio.
 */
class AddReferenceImagesRequest extends FormRequest
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
            'reference_images' => ['required', 'array', 'min:1', 'max:5'],
            'reference_images.*' => ['image', 'mimes:jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.required' => 'Debes adjuntar al menos una imagen.',
            'reference_images.max' => 'No puedes adjuntar más de 5 imágenes de referencia.',
            'reference_images.*.image' => 'Cada imagen de referencia debe ser un archivo de imagen válido.',
            'reference_images.*.mimes' => 'Tipo de archivo no permitido. Solo se aceptan imágenes JPG, PNG o WEBP.',
            'reference_images.*.max' => 'El tamaño de cada imagen de referencia excede el máximo permitido de 10 MB.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Support\ImageUploadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para agregar una foto a un Portafolio del Prestador
 * (POST /api/v1/profile/provider/portfolios/{portfolio}/items).
 *
 * Valida (Requisitos 3.2, 3.3, 3.4):
 *  - `image`: obligatorio, imagen de tipo JPG/PNG/WEBP, tamaño máximo 10 MB
 *    (10240 KB). Un archivo que incumpla tipo o tamaño se rechaza con un
 *    mensaje específico (tipo no permitido o tamaño excedido) y HTTP 422.
 *  - `description`: opcional, hasta 500 caracteres por imagen.
 *
 * La autorización por rol `prestador` activo la aplica el middleware
 * `role.provider` sobre la ruta; aquí solo se exige autenticación.
 */
class StorePortfolioItemRequest extends FormRequest
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
            'image' => ImageUploadRules::rules(),
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...ImageUploadRules::messages('image'),
            'description.max' => 'La descripción no puede exceder los 500 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image' => 'imagen',
            'description' => 'descripción',
        ];
    }
}

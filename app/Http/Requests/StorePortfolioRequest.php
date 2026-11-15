<?php

namespace App\Http\Requests;

use App\Rules\UniquePortfolioName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para crear un Portafolio
 * (POST /api/v1/profile/provider/portfolios).
 *
 * Valida (Requisitos 1.2, 1.3, 1.7, 1.9):
 *  - `name`: obligatorio, hasta 100 caracteres, único por Prestador sin
 *    distinguir mayúsculas. Los espacios exteriores los recorta el middleware
 *    TrimStrings; un nombre en blanco llega como null y `required` lo rechaza.
 *  - `description`: opcional, hasta 1000 caracteres.
 *
 * La autorización por rol la aplica `role.provider` sobre la ruta.
 */
class StorePortfolioRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:100',
                new UniquePortfolioName($this->user()?->providerProfile?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del portafolio es obligatorio.',
            'name.string' => 'El nombre del portafolio no es válido.',
            'name.max' => 'El nombre no puede exceder los 100 caracteres.',
            'description.max' => 'La descripción no puede exceder los 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'description' => 'descripción',
        ];
    }
}

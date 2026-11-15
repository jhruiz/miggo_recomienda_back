<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para actualizar la descripción de una foto de un Portafolio
 * (PUT /api/v1/profile/provider/portfolios/{portfolio}/items/{item}).
 *
 * Solo es editable la descripción (hasta 500 caracteres, Requisito 3.4). La
 * imagen en sí no se reemplaza por este endpoint: para cambiarla se elimina el
 * ítem y se vuelve a cargar.
 */
class UpdatePortfolioItemRequest extends FormRequest
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
            'description' => ['present', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.max' => 'La descripción no puede exceder los 500 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'description' => 'descripción',
        ];
    }
}

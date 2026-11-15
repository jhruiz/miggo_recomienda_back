<?php

namespace App\Http\Requests;

use App\Models\Portfolio;
use App\Rules\UniquePortfolioName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para actualizar un Portafolio
 * (PUT /api/v1/profile/provider/portfolios/{portfolio}).
 *
 * Igual que la creación, pero `name` es opcional y la regla de unicidad
 * excluye el propio Portafolio (Requisitos 1.4, 1.9).
 */
class UpdatePortfolioRequest extends FormRequest
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
        $portfolio = $this->route('portfolio');
        $ignoreId = $portfolio instanceof Portfolio
            ? $portfolio->id
            : (is_numeric($portfolio) ? (int) $portfolio : null);

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                new UniquePortfolioName($this->user()?->providerProfile?->id, $ignoreId),
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

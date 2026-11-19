<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros de consulta de GET /api/v1/wall/jobs y /api/v1/wall/portfolios.
 */
class IndexWallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:512'],
            'category_id' => ['sometimes', 'integer', 'exists:service_categories,id'],
            'city' => ['sometimes', 'string', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cursor.string' => 'El cursor no es válido.',
            'cursor.max' => 'El cursor no es válido.',
            'category_id.integer' => 'La categoría no existe.',
            'category_id.exists' => 'La categoría no existe.',
            'city.string' => 'La ciudad no es válida.',
            'city.min' => 'La ciudad no es válida.',
            'city.max' => 'La ciudad no es válida.',
        ];
    }

    /**
     * @return array{category_id: int|null, city: string|null}
     */
    public function filters(): array
    {
        return [
            'category_id' => $this->has('category_id') ? (int) $this->validated('category_id') : null,
            'city' => $this->has('city') ? (string) $this->validated('city') : null,
        ];
    }

    public function cursor(): ?string
    {
        return $this->has('cursor') ? (string) $this->validated('cursor') : null;
    }
}

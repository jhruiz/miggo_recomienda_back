<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros de consulta de GET /api/v1/wall/saved/jobs y /api/v1/wall/saved/portfolios.
 */
class IndexSavedRequest extends FormRequest
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
        ];
    }

    public function cursor(): ?string
    {
        return $this->has('cursor') ? (string) $this->validated('cursor') : null;
    }
}

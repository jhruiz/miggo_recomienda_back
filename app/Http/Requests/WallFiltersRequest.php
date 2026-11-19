<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros de GET /api/v1/wall/filters con pestaña: `tab` obligatorio (jobs|portfolios).
 */
class WallFiltersRequest extends FormRequest
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
            'tab' => ['required', 'string', Rule::in(['jobs', 'portfolios'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tab.required' => 'La pestaña es obligatoria.',
            'tab.string' => 'La pestaña no es válida.',
            'tab.in' => 'La pestaña no es válida.',
        ];
    }

    public function tab(): string
    {
        return (string) $this->validated('tab');
    }
}

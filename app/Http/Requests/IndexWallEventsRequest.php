<?php

namespace App\Http\Requests;

use App\Services\FeedPrivacyService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros de consulta de GET /api/v1/wall/privacy/events.
 */
class IndexWallEventsRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.FeedPrivacyService::MAX_PER_PAGE],
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
            'per_page.integer' => 'La cantidad por página debe ser un número entero.',
            'per_page.min' => 'La cantidad por página debe ser al menos 1.',
            'per_page.max' => 'La cantidad por página no puede superar '.FeedPrivacyService::MAX_PER_PAGE.'.',
        ];
    }

    public function cursor(): ?string
    {
        return $this->has('cursor') ? (string) $this->input('cursor') : null;
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', FeedPrivacyService::DEFAULT_PER_PAGE);
    }
}

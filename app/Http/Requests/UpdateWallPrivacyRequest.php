<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cuerpo de PUT /api/v1/wall/privacy.
 */
class UpdateWallPrivacyRequest extends FormRequest
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
            'behavior_logging' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'behavior_logging.required' => 'Indica si deseas activar el registro de comportamiento.',
            'behavior_logging.boolean' => 'El valor de registro de comportamiento no es válido.',
        ];
    }
}

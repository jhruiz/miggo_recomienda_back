<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para solicitar/confirmar la cancelación de mutuo acuerdo de un
 * Trabajo (POST /api/v1/jobs/{id}/request-cancel y /confirm-cancel, Requisitos
 * 7.7, 7.8).
 *
 * Valida la forma del motivo de cancelación: requerido y de máximo 500
 * caracteres. Si no cumple, Laravel retorna HTTP 422 sin que el servicio llegue
 * a tocar el Trabajo.
 *
 * Las reglas de negocio (ser una de las partes, que el Trabajo esté
 * "en_progreso") NO se evalúan aquí sino en el controlador/JobService (403 /
 * 409): este Form Request solo valida la entrada.
 */
class CancelJobRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Debes indicar el motivo de la cancelación.',
            'reason.max' => 'El motivo de cancelación no puede exceder los 500 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'motivo de cancelación',
        ];
    }
}

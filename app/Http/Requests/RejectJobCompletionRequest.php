<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para rechazar la finalización de un Trabajo
 * (POST /api/v1/jobs/{id}/reject-completion, Requisito 7.6).
 *
 * Valida la forma del motivo de rechazo: requerido y de máximo 500 caracteres.
 * Si no cumple, Laravel retorna HTTP 422 sin que el servicio llegue a tocar el
 * Trabajo.
 *
 * Las reglas de negocio (ser el Cliente dueño, que el Trabajo esté a la espera
 * de confirmación) NO se evalúan aquí sino en el controlador/JobService (403 /
 * 409): este Form Request solo valida la entrada.
 */
class RejectJobCompletionRequest extends FormRequest
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
            'reason.required' => 'Debes indicar el motivo del rechazo.',
            'reason.max' => 'El motivo de rechazo no puede exceder los 500 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'motivo de rechazo',
        ];
    }
}

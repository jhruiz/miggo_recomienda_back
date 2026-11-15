<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Form Request para actualizar los datos del perfil de prestador del usuario
 * autenticado (PUT /api/v1/profile/provider).
 *
 * Cubre los campos editables por el prestador: descripción profesional, Ciudad
 * del Catálogo_Ubicación (`city_id`) como ubicación canónica y
 * Dirección_Referencia opcional (`location`). Los campos cacheados
 * (average_rating, compliance_index, completed_jobs_count) NO son editables por
 * el usuario; se recalculan por el dominio. La verificación del rol `prestador`
 * activo es responsabilidad de la tarea 4.3; aquí solo se exige autenticación.
 *
 * `city_id` es opcional en la actualización parcial, pero cuando se envía debe
 * existir en el Catálogo_Ubicación y —si también se envía `department_id`—
 * pertenecer a ese Departamento; en caso contrario la operación se rechaza con
 * 422 sin persistir (Requisitos 3.10, 3.11, 3.12, 15.10).
 */
class UpdateProviderProfileRequest extends FormRequest
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
            'professional_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'city_id' => ['sometimes', 'required', 'integer', $this->cityExistsRule()],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Regla `exists` para `city_id`: la Ciudad debe existir en el
     * Catálogo_Ubicación y, cuando el formulario también envía
     * `department_id`, pertenecer a ese Departamento (Requisito 3.12).
     */
    private function cityExistsRule(): Exists
    {
        $rule = Rule::exists('cities', 'id');

        $departmentId = $this->input('department_id');

        if ($departmentId !== null && $departmentId !== '') {
            $rule->where('department_id', $departmentId);
        }

        return $rule;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'professional_description.max' => 'La descripción profesional no puede exceder los 2000 caracteres.',
            'city_id.required' => 'La ciudad no puede estar vacía.',
            'city_id.exists' => 'La ciudad seleccionada no es válida.',
            'department_id.exists' => 'El departamento seleccionado no es válido.',
            'location.max' => 'La dirección de referencia no puede exceder los 255 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'professional_description' => 'descripción profesional',
            'department_id' => 'departamento',
            'city_id' => 'ciudad',
            'location' => 'dirección de referencia',
        ];
    }
}

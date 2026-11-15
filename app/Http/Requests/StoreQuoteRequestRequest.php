<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Form Request para crear una Solicitud_Cotización (POST /api/v1/quote-requests,
 * Requisito 4.1).
 *
 * Valida: título (requerido, ≤100), descripción del servicio (requerida,
 * ≤2000), categoría de servicio (requerida, existente), Ciudad del
 * Catálogo_Ubicación (`city_id` requerido, existente y perteneciente al
 * Departamento indicado cuando se envía `department_id`), Dirección_Referencia
 * opcional (`location`, ≤255) y presupuesto estimado (opcional, numérico no
 * negativo). Si algún campo requerido falta o es inválido, Laravel retorna HTTP
 * 422 con el detalle por campo (sin persistir), según el envelope estándar
 * (Requisitos 4.10, 4.11, 4.12).
 *
 * La restricción de rol (solo Cliente) se aplica en la ruta vía auth:sanctum;
 * todo usuario autenticado posee el rol `cliente` (asignado al registrarse),
 * por lo que no se requiere un middleware de rol adicional aquí.
 */
class StoreQuoteRequestRequest extends FormRequest
{
    /** Número máximo de imágenes de referencia por solicitud (Requisito 4.13). */
    private const MAX_REFERENCE_IMAGES = 5;

    /** Tamaño máximo de cada imagen de referencia en KB (10 MB). */
    private const MAX_FILE_SIZE_KB = 10240;

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
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'city_id' => ['required', 'integer', $this->cityExistsRule()],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'estimated_budget' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            // Imágenes de referencia opcionales: hasta 5 archivos JPG/PNG/WEBP
            // de ≤10 MB cada uno (Requisitos 4.13, 4.14). Una solicitud sin
            // imágenes es válida.
            'reference_images' => ['sometimes', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['image', 'mimes:jpeg,png,webp', 'max:'.self::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * Regla `exists` para `city_id`: la Ciudad debe existir en el
     * Catálogo_Ubicación y, cuando el formulario también envía
     * `department_id`, pertenecer a ese Departamento (Requisito 4.12).
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
            'title.required' => 'El título es obligatorio.',
            'title.max' => 'El título no puede exceder los 100 caracteres.',
            'description.required' => 'La descripción del servicio es obligatoria.',
            'description.max' => 'La descripción no puede exceder los 2000 caracteres.',
            'service_category_id.required' => 'La categoría de servicio es obligatoria.',
            'service_category_id.exists' => 'La categoría de servicio seleccionada no existe.',
            'city_id.required' => 'La ciudad es obligatoria.',
            'city_id.exists' => 'La ciudad seleccionada no es válida.',
            'department_id.exists' => 'El departamento seleccionado no es válido.',
            'location.max' => 'La dirección de referencia no puede exceder los 255 caracteres.',
            'estimated_budget.numeric' => 'El presupuesto estimado debe ser un valor numérico.',
            'estimated_budget.min' => 'El presupuesto estimado no puede ser negativo.',
            'reference_images.array' => 'Las imágenes de referencia deben enviarse como una lista de archivos.',
            'reference_images.max' => 'No puedes adjuntar más de 5 imágenes de referencia.',
            'reference_images.*.image' => 'Cada imagen de referencia debe ser un archivo de imagen válido.',
            'reference_images.*.mimes' => 'Tipo de archivo no permitido. Solo se aceptan imágenes JPG, PNG o WEBP.',
            'reference_images.*.max' => 'El tamaño de cada imagen de referencia excede el máximo permitido de 10 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'description' => 'descripción',
            'service_category_id' => 'categoría de servicio',
            'department_id' => 'departamento',
            'city_id' => 'ciudad',
            'location' => 'dirección de referencia',
            'estimated_budget' => 'presupuesto estimado',
            'reference_images' => 'imágenes de referencia',
            'reference_images.*' => 'imagen de referencia',
        ];
    }
}

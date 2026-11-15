<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request para el listado y búsqueda pública de Solicitudes_Cotización
 * (GET /api/v1/quote-requests, Requisitos 4.3, 13.8, 13.9).
 *
 * Valida los parámetros de consulta de filtrado antes de ejecutar la búsqueda:
 *
 * - `category`      : slug de categoría de servicio existente.
 * - `department_id` : id de Departamento del Catálogo_Ubicación; filtra las
 *   solicitudes cuya Ciudad pertenece a ese Departamento.
 * - `city_id`       : id de Ciudad del Catálogo_Ubicación; acota a las
 *   solicitudes de esa Ciudad.
 * - `budget_min`  : presupuesto estimado mínimo (numérico no negativo).
 * - `budget_max`  : presupuesto estimado máximo; si ambos se envían, no puede
 *   ser menor que `budget_min`.
 * - `page` / `per_page` : paginación (20 elementos por página por defecto).
 *
 * Es un endpoint público: `authorize()` siempre retorna true. Un conjunto de
 * resultados vacío NO es un error (el controlador responde 200 con un mensaje);
 * solo los parámetros mal formados producen un 422.
 */
class IndexQuoteRequestsRequest extends FormRequest
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
            'category' => ['sometimes', 'nullable', 'string', Rule::exists('service_categories', 'slug')],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'city_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cities', 'id')],
            'budget_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'budget_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'gte:budget_min'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.exists' => 'La categoría seleccionada no existe.',
            'department_id.exists' => 'El departamento seleccionado no es válido.',
            'city_id.exists' => 'La ciudad seleccionada no es válida.',
            'budget_min.numeric' => 'El presupuesto mínimo debe ser un valor numérico.',
            'budget_min.min' => 'El presupuesto mínimo no puede ser negativo.',
            'budget_max.numeric' => 'El presupuesto máximo debe ser un valor numérico.',
            'budget_max.gte' => 'El presupuesto máximo no puede ser menor que el presupuesto mínimo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category' => 'categoría',
            'department_id' => 'departamento',
            'city_id' => 'ciudad',
            'budget_min' => 'presupuesto mínimo',
            'budget_max' => 'presupuesto máximo',
            'per_page' => 'elementos por página',
        ];
    }

    /**
     * Número de elementos por página solicitado, acotado al rango permitido.
     * Por defecto 20.
     */
    public function perPage(): int
    {
        $perPage = (int) $this->integer('per_page', 20);

        return max(1, min($perPage, 100));
    }

    /**
     * Filtros normalizados para pasar al servicio de búsqueda.
     *
     * La ubicación se filtra por identificadores del Catálogo_Ubicación
     * (`department_id`/`city_id`), no por texto libre (Requisitos 13.8, 13.9).
     *
     * @return array{category: ?string, department_id: ?int, city_id: ?int, budget_min: ?float, budget_max: ?float}
     */
    public function filters(): array
    {
        $trim = static fn (?string $value): ?string => ($value !== null && trim($value) !== '') ? trim($value) : null;

        return [
            'category' => $trim($this->input('category')),
            'department_id' => $this->filled('department_id') ? (int) $this->input('department_id') : null,
            'city_id' => $this->filled('city_id') ? (int) $this->input('city_id') : null,
            'budget_min' => $this->filled('budget_min') ? (float) $this->input('budget_min') : null,
            'budget_max' => $this->filled('budget_max') ? (float) $this->input('budget_max') : null,
        ];
    }
}
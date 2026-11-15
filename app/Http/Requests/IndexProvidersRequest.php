<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request para el listado y búsqueda pública de prestadores
 * (GET /api/v1/providers).
 *
 * Valida los parámetros de consulta antes de ejecutar la búsqueda
 * (Requisitos 13.2, 13.4, 13.5, 13.8, 13.9):
 *
 * - `category`      : slug de categoría de servicio existente.
 * - `department_id` : id de Departamento del Catálogo_Ubicación; filtra los
 *   prestadores cuya Ciudad pertenece a ese Departamento (Requisitos 13.8, 13.9).
 * - `city_id`       : id de Ciudad del Catálogo_Ubicación; acota el listado a
 *   los prestadores de esa Ciudad (Requisito 13.8).
 * - `rating_min` / `rating_max` : rango de calificación promedio expresado
 *   como valores entre 1.0 y 5.0; si ambos se envían, `rating_max` no puede
 *   ser menor que `rating_min`.
 * - `search`     : término de búsqueda por nombre/descripción, de 2 a 100
 *   caracteres; fuera de ese rango la solicitud se rechaza con 422 SIN
 *   ejecutar la búsqueda (Requisito 13.5).
 * - `page` / `per_page` : paginación (20 elementos por página por defecto).
 *
 * Es un endpoint público: `authorize()` siempre retorna true.
 */
class IndexProvidersRequest extends FormRequest
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
            'rating_min' => ['sometimes', 'nullable', 'numeric', 'between:1.0,5.0'],
            'rating_max' => ['sometimes', 'nullable', 'numeric', 'between:1.0,5.0', 'gte:rating_min'],
            'search' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],
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
            'rating_min.between' => 'La calificación mínima debe estar entre 1.0 y 5.0.',
            'rating_max.between' => 'La calificación máxima debe estar entre 1.0 y 5.0.',
            'rating_max.gte' => 'La calificación máxima no puede ser menor que la calificación mínima.',
            'search.min' => 'El término de búsqueda debe tener entre 2 y 100 caracteres.',
            'search.max' => 'El término de búsqueda debe tener entre 2 y 100 caracteres.',
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
            'rating_min' => 'calificación mínima',
            'rating_max' => 'calificación máxima',
            'search' => 'término de búsqueda',
            'per_page' => 'elementos por página',
        ];
    }

    /**
     * Número de elementos por página solicitado, acotado al rango permitido.
     * Por defecto 20 (Requisitos 13.1, 13.3).
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
     * @return array{category: ?string, department_id: ?int, city_id: ?int, rating_min: ?float, rating_max: ?float, search: ?string}
     */
    public function filters(): array
    {
        $trim = static fn (?string $value): ?string => ($value !== null && trim($value) !== '') ? trim($value) : null;

        return [
            'category' => $trim($this->input('category')),
            'department_id' => $this->filled('department_id') ? (int) $this->input('department_id') : null,
            'city_id' => $this->filled('city_id') ? (int) $this->input('city_id') : null,
            'rating_min' => $this->filled('rating_min') ? (float) $this->input('rating_min') : null,
            'rating_max' => $this->filled('rating_max') ? (float) $this->input('rating_max') : null,
            'search' => $trim($this->input('search')),
        ];
    }
}
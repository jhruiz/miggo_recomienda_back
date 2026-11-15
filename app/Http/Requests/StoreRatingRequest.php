<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para emitir una Calificación (POST /api/v1/ratings,
 * Requisitos 8.2, 8.3).
 *
 * Valida:
 *   - job_id : requerido, entero, existente en la tabla de Trabajos
 *     (`service_jobs`).
 *   - score  : requerido, ENTERO estricto entre 1 y 5 (Requisito 8.2). La
 *     regla `integer` + `between` rechaza valores no enteros o fuera de rango.
 *   - comment: requerido, cadena de 10 a 500 caracteres (Requisito 8.2).
 *
 * Si algún campo falta o es inválido, Laravel retorna HTTP 422 con el detalle
 * por campo SIN persistir la Calificación (Requisito 8.3), según el envelope
 * estándar { data, message, errors }.
 */
class StoreRatingRequest extends FormRequest
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
            'job_id' => ['required', 'integer', 'exists:service_jobs,id'],
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'job_id.required' => 'El trabajo es obligatorio.',
            'job_id.exists' => 'El trabajo indicado no existe.',
            'score.required' => 'La puntuación es obligatoria.',
            'score.integer' => 'La puntuación debe ser un número entero entre 1 y 5.',
            'score.between' => 'La puntuación debe estar entre 1 y 5.',
            'comment.required' => 'El comentario es obligatorio.',
            'comment.min' => 'El comentario debe tener al menos 10 caracteres.',
            'comment.max' => 'El comentario no puede exceder los 500 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'job_id' => 'trabajo',
            'score' => 'puntuación',
            'comment' => 'comentario',
        ];
    }
}

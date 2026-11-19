<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para solicitar una Cotización directa a un Prestador concreto
 * (POST /api/v1/quotes/direct).
 *
 * Tarea 11.1 (Requisitos 10.1, 10.5, 10.6). El Cliente autenticado indica el
 * Prestador objetivo mediante `provider_user_id`; opcionalmente puede incluir
 * una `message` describiendo lo que necesita.
 *
 *  - `provider_user_id` : requerido, debe existir en `users`. Que el usuario
 *    tenga el rol de Prestador activo (Requisito 10.5) y que no se supere el
 *    límite de solicitudes activas (Requisito 10.6) son reglas de negocio que
 *    valida QuoteService, no esta validación de entrada.
 *  - `message`          : opcional, ≤1000 caracteres. Se guarda como
 *    descripción inicial de la solicitud mientras está en estado "solicitada".
 *
 * La restricción de rol del solicitante NO aplica aquí: cualquier usuario
 * autenticado (en su faceta de Cliente) puede solicitar una Cotización directa.
 */
class StoreDirectQuoteRequest extends FormRequest
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
            'provider_user_id' => ['required', 'integer', 'exists:users,id'],
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_user_id.required' => 'Debes indicar el prestador al que solicitas la cotización.',
            'provider_user_id.exists' => 'El prestador indicado no existe.',
            'service_category_id.required' => 'Debes indicar la categoría del servicio que necesitas.',
            'service_category_id.integer' => 'La categoría del servicio no es válida.',
            'service_category_id.exists' => 'La categoría indicada no existe.',
            'message.max' => 'El mensaje no puede exceder los 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider_user_id' => 'prestador',
            'service_category_id' => 'categoría',
            'message' => 'mensaje',
        ];
    }
}

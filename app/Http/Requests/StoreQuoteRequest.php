<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para crear una Cotización (POST /api/v1/quotes).
 *
 * Tarea 8.1 (Requisitos 5.1, 5.2, 5.3, 5.5, 6.1, 6.5). Valida y rechaza con
 * HTTP 422 sin persistir (Requisito 5.5) cuando algún campo está fuera de los
 * rangos permitidos:
 *
 *  - `quote_request_id`  : requerido para la Cotización pública de esta tarea;
 *    debe existir. (La Cotización directa, que prescinde de solicitud previa,
 *    es la tarea 11.1 y añadirá su propia validación aditivamente.)
 *  - `service_description`: requerida, ≤1000 caracteres (Requisito 5.1).
 *  - `items`             : requerido, de 1 a 50 ítems (Requisito 5.2).
 *  - `items.*.description`: requerida, ≤1000 caracteres.
 *  - `items.*.unit_price` : requerido, numérico entre 0.01 y 999,999,999.99
 *    (Requisito 5.1).
 *  - `items.*.quantity`   : requerido, entero entre 1 y 9,999 (Requisito 5.1).
 *  - `delivery_days`     : requerido, entero entre 1 y 365 (Requisito 5.1).
 *  - `expiration_days`   : requerido, entero entre 1 y 30 (Requisitos 6.1, 6.5).
 *  - `attachments`       : opcional, hasta 5 imágenes JPG/PNG/WEBP de ≤10 MB
 *    cada una (Requisito 5.3).
 *
 * La restricción de rol (solo Prestador) la aplica el middleware
 * `role.provider` sobre la ruta; aquí solo se exige autenticación.
 */
class StoreQuoteRequest extends FormRequest
{
    /** Tamaño máximo de cada adjunto en KB (10 MB). */
    private const MAX_ATTACHMENT_SIZE_KB = 10240;

    /** Máximo de ítems por Cotización (Requisito 5.2). */
    public const MAX_ITEMS = 50;

    /** Máximo de adjuntos por Cotización (Requisito 5.3). */
    public const MAX_ATTACHMENTS = 5;

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
            'quote_request_id' => ['required', 'integer', 'exists:quote_requests,id'],
            'service_description' => ['required', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1', 'max:' . self::MAX_ITEMS],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],

            'delivery_days' => ['required', 'integer', 'min:1', 'max:365'],
            'expiration_days' => ['required', 'integer', 'min:1', 'max:30'],

            'attachments' => ['sometimes', 'nullable', 'array', 'max:' . self::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::MAX_ATTACHMENT_SIZE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quote_request_id.required' => 'La solicitud de cotización es obligatoria.',
            'quote_request_id.exists' => 'La solicitud de cotización indicada no existe.',
            'service_description.required' => 'La descripción del servicio es obligatoria.',
            'service_description.max' => 'La descripción del servicio no puede exceder los 1000 caracteres.',

            'items.required' => 'La cotización debe incluir al menos un ítem.',
            'items.min' => 'La cotización debe incluir al menos un ítem.',
            'items.max' => 'La cotización no puede incluir más de 50 ítems.',
            'items.*.description.required' => 'Cada ítem debe tener una descripción.',
            'items.*.description.max' => 'La descripción de cada ítem no puede exceder los 1000 caracteres.',
            'items.*.unit_price.required' => 'Cada ítem debe tener un precio unitario.',
            'items.*.unit_price.min' => 'El precio unitario debe ser al menos 0.01.',
            'items.*.unit_price.max' => 'El precio unitario no puede exceder 999,999,999.99.',
            'items.*.quantity.required' => 'Cada ítem debe tener una cantidad.',
            'items.*.quantity.min' => 'La cantidad de cada ítem debe ser al menos 1.',
            'items.*.quantity.max' => 'La cantidad de cada ítem no puede exceder 9,999.',

            'delivery_days.required' => 'El tiempo estimado de entrega es obligatorio.',
            'delivery_days.min' => 'El tiempo estimado de entrega debe ser de al menos 1 día.',
            'delivery_days.max' => 'El tiempo estimado de entrega no puede exceder los 365 días.',

            'expiration_days.required' => 'El plazo de expiración es obligatorio.',
            'expiration_days.min' => 'El plazo de expiración debe estar entre 1 y 30 días.',
            'expiration_days.max' => 'El plazo de expiración debe estar entre 1 y 30 días.',

            'attachments.max' => 'No puedes adjuntar más de 5 imágenes.',
            'attachments.*.mimes' => 'Tipo de archivo no permitido. Solo se aceptan imágenes JPG, PNG o WEBP.',
            'attachments.*.max' => 'El tamaño de cada imagen no puede exceder los 10 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quote_request_id' => 'solicitud de cotización',
            'service_description' => 'descripción del servicio',
            'items' => 'ítems',
            'delivery_days' => 'tiempo estimado de entrega',
            'expiration_days' => 'plazo de expiración',
            'attachments' => 'adjuntos',
        ];
    }
}

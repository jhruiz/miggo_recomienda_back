<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request de GET /quotes/sent y GET /quotes/received.
 */
class IndexMyQuotesRequest extends FormRequest
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
        $allowedStatuses = $this->isAwaitingView()
            ? [Quote::STATUS_SOLICITADA, Quote::STATUS_SIN_RESPUESTA]
            : [
                Quote::STATUS_PENDIENTE,
                Quote::STATUS_ACEPTADA,
                Quote::STATUS_RECHAZADA,
                Quote::STATUS_EXPIRADA,
                Quote::STATUS_NO_SELECCIONADA,
                Quote::STATUS_SOLICITADA,
                Quote::STATUS_SIN_RESPUESTA,
            ];

        return [
            'view' => ['sometimes', 'nullable', 'string', Rule::in(['received', 'awaiting'])],
            'status' => ['sometimes', 'nullable', 'string', Rule::in($allowedStatuses)],
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
            'view.in' => 'La vista indicada no es válida. Usa "received" o "awaiting".',
            'status.in' => $this->isAwaitingView()
                ? 'En la vista de espera, el estado solo puede ser "solicitada" o "sin_respuesta".'
                : 'El estado indicado no es válido.',
        ];
    }

    public function isAwaitingView(): bool
    {
        return $this->input('view') === 'awaiting';
    }

    public function perPage(): int
    {
        return max(1, min((int) $this->integer('per_page', 20), 100));
    }

    public function statusFilter(): ?string
    {
        return $this->filled('status') ? (string) $this->input('status') : null;
    }
}

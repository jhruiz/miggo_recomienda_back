<?php

namespace App\Http\Requests;

use App\Enums\FeedEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cuerpo de POST /api/v1/wall/events.
 *
 * Solo admite los tipos que origina el cliente (DD7). El usuario nunca se lee del
 * cuerpo: sale del contexto de autenticación, así que cualquier `user_id` se ignora.
 */
class StoreWallEventsRequest extends FormRequest
{
    public const MAX_EVENTS = 50;

    /**
     * Sujetos admitidos.
     */
    public const SUBJECT_TYPES = ['portfolio', 'job'];

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
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['required', 'array'],
            'events.*.subject_type' => ['required', 'string', Rule::in(self::SUBJECT_TYPES)],
            'events.*.subject_id' => ['required', 'integer', 'min:1'],
            'events.*.type' => ['required', 'string', Rule::in($this->clientTypes())],
            'events.*.occurred_at' => ['nullable', 'date', 'before_or_equal:now', 'after_or_equal:-24 hours'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.required' => 'Debes enviar al menos un evento.',
            'events.array' => 'Los eventos no tienen un formato válido.',
            'events.min' => 'Debes enviar al menos un evento.',
            'events.max' => 'Un lote admite como máximo '.self::MAX_EVENTS.' eventos.',
            'events.*.required' => 'El evento no es válido.',
            'events.*.array' => 'El evento no es válido.',
            'events.*.subject_type.required' => 'Indica el tipo de sujeto del evento.',
            'events.*.subject_type.in' => 'El tipo de sujeto no está permitido.',
            'events.*.subject_id.required' => 'Indica el sujeto del evento.',
            'events.*.subject_id.integer' => 'El sujeto del evento no es válido.',
            'events.*.subject_id.min' => 'El sujeto del evento no es válido.',
            'events.*.type.required' => 'Indica el tipo de evento.',
            'events.*.type.in' => 'El tipo de evento no está permitido.',
            'events.*.occurred_at.date' => 'La fecha del evento no es válida.',
            'events.*.occurred_at.before_or_equal' => 'La fecha del evento no puede ser futura.',
            'events.*.occurred_at.after_or_equal' => 'La fecha del evento no puede tener más de 24 horas de antigüedad.',
        ];
    }

    /**
     * @return list<string>
     */
    private function clientTypes(): array
    {
        return array_values(array_map(
            static fn (FeedEventType $type): string => $type->value,
            array_filter(FeedEventType::cases(), static fn (FeedEventType $type): bool => $type->isClientOriginated()),
        ));
    }
}

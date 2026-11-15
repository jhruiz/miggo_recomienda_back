<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para enviar un mensaje de Chat
 * (POST /api/v1/chat/channels/{id}/messages, Requisitos 11.5, 11.8).
 *
 * Valida que el contenido esté presente y tenga entre 1 y 2000 caracteres. Un
 * contenido ausente, vacío (incluido whitespace puro) o que exceda 2000
 * caracteres se rechaza con HTTP 422 sin llegar al servicio ni persistir el
 * mensaje (Requisito 11.8).
 *
 * El contenido se normaliza recortando el whitespace de los extremos ANTES de
 * validar, de modo que un mensaje de solo espacios se considera vacío. El
 * control de acceso (participante del canal) y el estado del canal (Requisito
 * 11.7) se verifican en el controlador/servicio, no aquí.
 */
class SendChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normaliza el contenido recortando espacios en los extremos antes de
     * aplicar las reglas de longitud, para que un mensaje de solo whitespace
     * sea tratado como vacío (Requisito 11.8).
     */
    protected function prepareForValidation(): void
    {
        $content = $this->input('content');

        if (is_string($content)) {
            $this->merge(['content' => trim($content)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'El contenido del mensaje es obligatorio.',
            'content.min' => 'El contenido del mensaje no puede estar vacío.',
            'content.max' => 'El contenido del mensaje no puede exceder los 2000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content' => 'contenido',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para el inicio de sesión (POST /api/v1/auth/login).
 *
 * Solo valida la presencia y el tipo de los campos; la verificación de
 * credenciales (y el mensaje genérico ante credenciales inválidas) se
 * realiza en AuthService::login() para no revelar qué campo es incorrecto.
 */
class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Form Request de registro de usuario.
 *
 * Reglas de validación según el Requisito 1.1 y 1.3 del diseño:
 *  - name:     requerido, cadena, máximo 100 caracteres.
 *  - email:    requerido, formato de email válido, único en la tabla `users`.
 *  - password: requerido, confirmado (campo `password_confirmation`),
 *              mínimo 8 y máximo 72 caracteres.
 *
 * El límite máximo de 72 caracteres de la contraseña coincide con el límite
 * de bytes que admite el algoritmo bcrypt usado por Laravel para el hash.
 */
class RegisterRequest extends FormRequest
{
    /**
     * El registro es un endpoint público; cualquiera puede autorizarlo.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de validación aplicadas a la petición de registro.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->max(72),
            ],
        ];
    }

    /**
     * Mensajes de validación personalizados (en español).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede exceder los 100 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'email.unique' => 'El correo electrónico ya está en uso.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede exceder los 72 caracteres.',
        ];
    }

    /**
     * Nombres de atributos legibles para los mensajes de error.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
        ];
    }
}

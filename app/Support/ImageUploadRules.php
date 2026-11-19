<?php

namespace App\Support;

/**
 * Reglas de validación compartidas para imágenes subidas (fotos de Portafolio
 * y Fotos_Trabajo): JPG/PNG/WEBP, máximo 10 MB (10240 KB).
 */
final class ImageUploadRules
{
    /** Tamaño máximo del archivo en KB (10 MB). */
    public const MAX_KB = 10240;

    /**
     * Reglas de un campo de imagen obligatorio.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return [
            'required',
            'file',
            'mimes:jpg,jpeg,png,webp',
            'max:'.self::MAX_KB,
        ];
    }

    /**
     * Mensajes en español para las reglas anteriores, claves `{campo}.{regla}`.
     *
     * @return array<string, string>
     */
    public static function messages(string $field = 'image'): array
    {
        return [
            "{$field}.required" => 'Debes adjuntar una imagen.',
            "{$field}.file" => 'El archivo adjunto no es válido.',
            "{$field}.mimes" => 'Tipo de archivo no permitido. Solo se aceptan imágenes JPG, PNG o WEBP.',
            "{$field}.max" => 'El tamaño del archivo excede el máximo permitido de 10 MB.',
        ];
    }
}

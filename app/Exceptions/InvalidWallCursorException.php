<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando el cursor de paginación del Muro está
 * mal formado, fue alterado (firma inválida) o corresponde a otro tipo de
 * listado. El controlador la traduce a HTTP 422 con `errors.cursor`.
 */
class InvalidWallCursorException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'El cursor no es válido.');
    }
}

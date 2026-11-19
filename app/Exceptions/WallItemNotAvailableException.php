<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un Trabajo o Portafolio no existe o no es
 * elegible para el Muro. El controlador la traduce a HTTP 404.
 */
class WallItemNotAvailableException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'El elemento no existe o no está disponible.');
    }
}

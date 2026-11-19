<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un lote de eventos del Muro supera el
 * máximo permitido. El controlador la traduce a HTTP 422.
 */
class WallEventBatchTooLargeException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Un lote admite como máximo 50 eventos.');
    }
}

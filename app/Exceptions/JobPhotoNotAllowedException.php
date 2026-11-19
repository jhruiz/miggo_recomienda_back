<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza al subir fotos con el Trabajo en un estado no admitido (cancelado o
 * en progreso sin cerrar por el Prestador). El controlador la traduce a HTTP 409.
 */
class JobPhotoNotAllowedException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct(
            $message !== ''
                ? $message
                : 'Solo puedes subir fotos cuando el trabajo está cerrado por ti o completado.',
        );
    }

    public function getStatus(): int
    {
        return 409;
    }
}

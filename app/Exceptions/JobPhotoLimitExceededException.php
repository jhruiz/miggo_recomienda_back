<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando agregar fotos superaría el máximo de Fotos_Trabajo por
 * Trabajo. El controlador la traduce a HTTP 422 con el envelope estándar.
 */
class JobPhotoLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $current = 0,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "Un trabajo admite como máximo {$limit} fotos de resultado. Ya tiene {$current}.",
        );
    }

    public function getStatus(): int
    {
        return 422;
    }
}

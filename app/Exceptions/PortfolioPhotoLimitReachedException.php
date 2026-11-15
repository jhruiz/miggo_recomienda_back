<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un Prestador intenta agregar una foto
 * a un portafolio que ya alcanzó el máximo de fotos permitido.
 *
 * Es un error de negocio (no de validación de entrada): la petición es
 * sintácticamente válida pero viola una regla de estado del dominio. El
 * controlador la traduce a una respuesta HTTP 409 (Conflict) con el envelope
 * estándar { data, message }.
 */
class PortfolioPhotoLimitReachedException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "Este portafolio alcanzó el máximo de {$limit} fotos.",
        );
    }
}

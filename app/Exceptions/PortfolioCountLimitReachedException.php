<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un Prestador intenta crear un portafolio
 * habiendo alcanzado el máximo de portafolios permitido.
 *
 * El controlador la traduce a una respuesta HTTP 409 (Conflict) con el
 * envelope estándar { data, message }.
 */
class PortfolioCountLimitReachedException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "Alcanzaste el máximo de {$limit} portafolios.",
        );
    }
}

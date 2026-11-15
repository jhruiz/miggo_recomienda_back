<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un Usuario intenta aceptar o rechazar una
 * Cotización de la que no es el Cliente receptor.
 *
 * Solo el Cliente al que va dirigida la Cotización (`client_user_id`) puede
 * aceptarla o rechazarla (Requisitos 5.7, 5.8). Cualquier otro usuario —incluido
 * el Prestador que la creó— recibe un error de autorización. El controlador la
 * traduce a HTTP 403 (Forbidden) con el envelope estándar { data, message }.
 */
class QuoteNotAuthorizedException extends RuntimeException
{
    public function __construct(string $message = 'No tienes permiso para realizar esta acción sobre la cotización.')
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un Cliente intenta editar o cerrar una
 * Solicitud_Cotización que ya NO está en estado "abierta" (Requisitos 4.6, 4.7).
 *
 * Es un error de negocio (no de validación de entrada): la petición es
 * sintácticamente válida pero viola una regla de estado del dominio (solo las
 * solicitudes "abiertas" admiten edición o cierre manual). El controlador la
 * traduce a una respuesta HTTP 409 (Conflict) con el envelope estándar
 * { data, message }.
 */
class QuoteRequestNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly string $currentStatus,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : 'Solo puedes editar o cerrar una solicitud que esté en estado "abierta".',
        );
    }
}

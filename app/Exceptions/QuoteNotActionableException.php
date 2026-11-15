<?php

namespace App\Exceptions;

use App\Models\Quote;
use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un Cliente intenta aceptar o rechazar una
 * Cotización que no admite esa acción por su estado actual.
 *
 * Es un error de negocio (no de validación de entrada): la petición es
 * sintácticamente válida pero viola una regla de estado del dominio. Solo una
 * Cotización en estado "pendiente" puede aceptarse o rechazarse. En particular,
 * una Cotización "expirada" no puede aceptarse ni rechazarse (Requisito 6.4), y
 * una ya "aceptada", "rechazada" o "no_seleccionada" tampoco. El controlador la
 * traduce a HTTP 409 (Conflict) con el envelope estándar { data, message }.
 */
class QuoteNotActionableException extends RuntimeException
{
    public function __construct(
        public readonly string $currentStatus,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : 'La cotización no está pendiente y no admite esta acción.',
        );
    }

    /**
     * La Cotización ha vencido: no puede aceptarse ni rechazarse (Requisito 6.4).
     */
    public static function expired(Quote $quote): self
    {
        return new self(
            (string) $quote->status,
            'La cotización ha vencido y ya no puede aceptarse ni rechazarse.',
        );
    }

    /**
     * La Cotización no está "pendiente" (p. ej. ya aceptada, rechazada o no
     * seleccionada), por lo que no admite aceptación ni rechazo.
     */
    public static function notPending(Quote $quote): self
    {
        return new self((string) $quote->status);
    }

    /**
     * La Cotización directa no está en estado "solicitada", por lo que el
     * Prestador ya no puede responderla (p. ej. ya respondió -> "pendiente", o
     * venció -> "sin_respuesta"). Tarea 11.1 (Requisito 10.2).
     */
    public static function notSolicitada(Quote $quote): self
    {
        return new self(
            (string) $quote->status,
            'La solicitud de cotización directa ya no admite respuesta.',
        );
    }
}

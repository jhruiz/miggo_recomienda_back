<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio para los errores al postular un Prestador a una
 * Solicitud_Cotización (POST /quote-requests/{id}/apply, Requisitos 4.4, 4.8,
 * tarea 7.2).
 *
 * Ambos casos son conflictos de estado del dominio que se traducen a HTTP 409
 * (Conflict): la petición es sintácticamente válida pero viola una regla de
 * negocio.
 *
 *  - Postulación duplicada (409): el Prestador ya postuló a esta Solicitud
 *    (Requisito 4.4). La restricción única en la tabla
 *    `quote_request_applications` lo garantiza también a nivel de datos.
 *  - Solicitud no "abierta" (409): la Solicitud no admite postulaciones porque
 *    su estado no es "abierta" (Requisito 4.8).
 *
 * El controlador usa `getStatus()` para traducir cada caso a su respuesta HTTP
 * con el envelope estándar { data, message }.
 */
class QuoteRequestApplicationException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    /**
     * HTTP 409: el Prestador ya ha postulado a esta Solicitud (Requisito 4.4).
     */
    public static function duplicate(): self
    {
        return new self(
            'Ya has postulado a esta solicitud de cotización.',
            409,
        );
    }

    /**
     * HTTP 409: la Solicitud no está "abierta" y no admite postulaciones
     * (Requisito 4.8).
     */
    public static function notOpen(): self
    {
        return new self(
            'Esta solicitud no está disponible para postulaciones.',
            409,
        );
    }

    /**
     * HTTP 403: el Prestador es el dueño de la Solicitud y no puede postular
     * a la propia.
     */
    public static function ownRequest(): self
    {
        return new self(
            'No puedes postular a tu propia solicitud de cotización.',
            403,
        );
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

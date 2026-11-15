<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio para los errores al seleccionar al Prestador ganador de
 * una Solicitud_Cotización (Requisito 4.5, tarea 7.5).
 *
 * Distingue dos clases de error de negocio, cada una con su código HTTP:
 *
 *  - Conflicto de estado (409): la Solicitud no está "abierta", por lo que no
 *    admite seleccionar un ganador (ya está "en proceso" o "cerrada").
 *  - Entidad no procesable (422): el Prestador indicado no ha postulado a la
 *    Solicitud, por lo que no puede ser seleccionado.
 *
 * El controlador usa `getStatus()` para traducir cada caso a su respuesta HTTP
 * con el envelope estándar { data, message }.
 */
class ProviderSelectionException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    /**
     * HTTP 409: la Solicitud no está en estado "abierta".
     */
    public static function notOpen(string $currentStatus): self
    {
        return new self(
            'Solo puedes seleccionar un Prestador en una solicitud que esté en estado "abierta".',
            409,
        );
    }

    /**
     * HTTP 422: el Prestador indicado no ha postulado a la Solicitud.
     */
    public static function providerNotApplicant(): self
    {
        return new self(
            'El Prestador seleccionado no ha postulado a esta solicitud.',
            422,
        );
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

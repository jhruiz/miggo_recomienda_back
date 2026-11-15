<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio para la solicitud de Cotización directa (tarea 11.1,
 * Requisitos 10.5, 10.6).
 *
 * Es un error de negocio (no de validación de entrada): la petición es
 * sintácticamente válida pero viola una regla del dominio. Dos casos:
 *
 *  - providerUnavailable (Requisito 10.5): el Prestador objetivo no tiene el
 *    rol de Prestador activo, por lo que no puede recibir solicitudes directas.
 *    El controlador lo traduce a HTTP 422 (entidad no procesable: el objetivo
 *    no es válido como destinatario).
 *  - activeLimitReached (Requisito 10.6): el Cliente ya tiene 5 o más
 *    solicitudes directas activas (en estado "solicitada" o "sin_respuesta")
 *    hacia el mismo Prestador. El controlador lo traduce a HTTP 409 (conflicto
 *    de estado: se alcanzó un límite acumulado).
 *
 * Ambos se exponen con el envelope estándar { data, message }.
 */
class DirectQuoteRequestException extends RuntimeException
{
    /** Máximo de solicitudes directas activas por par (Cliente, Prestador). */
    public const MAX_ACTIVE_REQUESTS = 5;

    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * El Prestador objetivo no está disponible (sin rol prestador activo):
     * HTTP 422 (Requisito 10.5).
     */
    public static function providerUnavailable(): self
    {
        return new self(
            422,
            'El prestador no está disponible para recibir solicitudes de cotización.',
        );
    }

    /**
     * El Cliente alcanzó el límite de solicitudes directas activas hacia ese
     * mismo Prestador: HTTP 409 (Requisito 10.6).
     */
    public static function activeLimitReached(): self
    {
        return new self(
            409,
            'Has alcanzado el límite de solicitudes de cotización activas hacia este prestador.',
        );
    }
}

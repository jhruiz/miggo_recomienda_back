<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio para los errores al transicionar el estado de un Trabajo
 * en el flujo de completar / confirmar / rechazar finalización (Requisitos 7.3,
 * 7.4, 7.6, tarea 9.2).
 *
 * Representa un conflicto de estado del dominio: la petición es sintácticamente
 * válida pero el Trabajo no está en un estado que admita la acción solicitada
 * (p. ej. intentar confirmar un Trabajo que ya está "completado" o "cancelado").
 * El controlador la traduce a HTTP 409 (Conflict) con el envelope estándar
 * { data, message }.
 */
class JobTransitionException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    /**
     * HTTP 409: solo un Trabajo "en progreso" puede marcarse como completado
     * por el Prestador (Requisito 7.3).
     */
    public static function notInProgressForCompletion(): self
    {
        return new self(
            'Solo puedes marcar como completado un trabajo que esté en progreso.',
        );
    }

    /**
     * HTTP 409: solo un Trabajo "en progreso" con finalización marcada por el
     * Prestador puede ser confirmado o rechazado por el Cliente (Requisitos
     * 7.4, 7.6).
     */
    public static function notAwaitingConfirmation(): self
    {
        return new self(
            'Este trabajo no está a la espera de que confirmes o rechaces su finalización.',
        );
    }

    /**
     * HTTP 409: solo un Trabajo "en progreso" admite solicitudes de cancelación
     * de mutuo acuerdo (Requisitos 7.7, 7.8). Un Trabajo ya "completado" o
     * "cancelado" no puede cancelarse.
     */
    public static function notInProgressForCancellation(): self
    {
        return new self(
            'Solo puedes solicitar la cancelación de un trabajo que esté en progreso.',
        );
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

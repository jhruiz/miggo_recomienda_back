<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada cuando la emisión de una Calificación viola una
 * regla de negocio del Gestor_Calificaciones (Requisitos 8.1, 8.6, 8.7).
 *
 * NO cubre la validación de entrada (score/comentario fuera de rango), que la
 * resuelve el Form Request con HTTP 422. Aquí se modelan los conflictos de
 * estado del dominio:
 *   - Las Calificaciones aún no están habilitadas para el Trabajo, porque no
 *     está "completado" (Requisito 8.1) → HTTP 409.
 *   - Ya existe una Calificación del mismo Usuario para el Trabajo
 *     (Requisito 8.6) → HTTP 409.
 *   - La ventana de calificación (30 días desde la habilitación) ya se cerró
 *     para el Usuario (Requisito 8.7) → HTTP 409.
 *   - El Usuario no es parte del Trabajo y por tanto no puede calificarlo
 *     (Requisito 8.1) → HTTP 403.
 *
 * El controlador traduce la excepción a su `status()` con el envelope estándar
 * { data, message }.
 */
class RatingSubmissionException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Las Calificaciones no están habilitadas: el Trabajo no está "completado"
     * (Requisito 8.1).
     */
    public static function notEnabled(): self
    {
        return new self(
            'Las calificaciones aún no están habilitadas para este trabajo.',
            409,
        );
    }

    /**
     * El Usuario no es parte del Trabajo (ni Cliente ni Prestador), por lo que
     * no puede calificarlo (Requisito 8.1).
     */
    public static function notParticipant(): self
    {
        return new self(
            'No formas parte de este trabajo, por lo que no puedes calificarlo.',
            403,
        );
    }

    /**
     * El Usuario ya emitió su Calificación para este Trabajo (Requisito 8.6).
     */
    public static function alreadyRated(): self
    {
        return new self(
            'Ya existe una calificación registrada para este trabajo.',
            409,
        );
    }

    /**
     * La ventana de 30 días para calificar se cerró para el Usuario
     * (Requisito 8.7).
     */
    public static function windowClosed(): self
    {
        return new self(
            'El plazo para calificar este trabajo ha expirado.',
            409,
        );
    }
}

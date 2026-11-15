<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de negocio al gestionar las imágenes de referencia de una
 * Solicitud_Cotización (Requisitos 4.13, 4.16).
 *
 *  - 409: la solicitud no está "abierta".
 *  - 422: se superaría el tope de 5 imágenes, o la imagen no pertenece a la solicitud.
 */
class QuoteRequestImageException extends RuntimeException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notOpen(string $status): self
    {
        return new self(
            "Solo se pueden modificar las imágenes de una solicitud abierta (estado actual: {$status}).",
            409,
        );
    }

    public static function limitReached(int $limit): self
    {
        return new self("Una solicitud no puede tener más de {$limit} imágenes de referencia.", 422);
    }

    public static function notFound(): self
    {
        return new self('La imagen indicada no pertenece a esta solicitud.', 404);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

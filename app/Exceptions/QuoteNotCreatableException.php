<?php

namespace App\Exceptions;

use App\Models\QuoteRequest;
use RuntimeException;

/**
 * Excepción de dominio lanzada cuando un Prestador intenta crear una Cotización
 * sobre una Solicitud_Cotización que no admite nuevas Cotizaciones.
 *
 * Es un error de negocio (no de validación de entrada): la petición es
 * sintácticamente válida pero viola una regla de estado del dominio. Una
 * Cotización pública solo puede enviarse mientras la Solicitud asociada está
 * en estado "abierta"; una Solicitud "en proceso" o "cerrada" ya no recibe
 * propuestas. El controlador la traduce a HTTP 409 (Conflict) con el envelope
 * estándar { data, message }.
 */
class QuoteNotCreatableException extends RuntimeException
{
    public function __construct(
        public readonly string $currentStatus,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : 'La solicitud de cotización no está abierta y no admite nuevas cotizaciones.',
        );
    }

    public static function requestNotOpen(QuoteRequest $quoteRequest): self
    {
        return new self((string) $quoteRequest->status);
    }
}

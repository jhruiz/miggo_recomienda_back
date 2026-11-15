<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción de dominio lanzada al intentar enviar un mensaje de Chat en un
 * canal que no admite nuevos mensajes (Requisito 11.7).
 *
 * Es un error de negocio (no de validación de entrada): la petición es
 * sintácticamente válida pero viola una regla de estado del dominio (un canal
 * "cerrado" no admite el envío de mensajes). El controlador la traduce a una
 * respuesta HTTP 409 (Conflict) con el envelope estándar { data, message }.
 *
 * La validación de contenido (vacío / >2000 caracteres, Requisito 11.8) NO se
 * modela aquí: la gestiona el Form Request (HTTP 422) antes de llegar al
 * servicio.
 */
class ChatMessageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    /**
     * El canal está "cerrado" y no admite nuevos mensajes (Requisito 11.7).
     */
    public static function channelClosed(): self
    {
        return new self(
            'El canal de chat está cerrado y no admite nuevos mensajes.',
            status: 409,
        );
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

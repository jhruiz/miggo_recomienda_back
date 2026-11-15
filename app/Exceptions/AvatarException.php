<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de negocio al guardar o eliminar la Foto_Perfil de un Usuario.
 *
 *  - 500: falla de almacenamiento, conversión o eliminación.
 */
class AvatarException extends RuntimeException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function storeFailed(): self
    {
        return new self('No se pudo guardar la foto. Inténtalo de nuevo.', 500);
    }

    public static function deleteFailed(): self
    {
        return new self('No se pudo eliminar la foto. Inténtalo de nuevo.', 500);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

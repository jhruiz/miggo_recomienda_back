<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Fábrica centralizada del envelope de respuesta JSON de la API.
 *
 * Todas las respuestas siguen la forma estándar definida en el diseño:
 *   { "data": ..., "message": ..., "errors"?: ..., "meta"?: ... }
 *
 * - `data`    : carga útil de la respuesta (objeto, lista o null).
 * - `message` : mensaje legible para el cliente.
 * - `errors`  : presente solo en respuestas de error (indexado por campo en validación 422).
 * - `meta`    : presente solo cuando hay metadatos (p. ej. paginación).
 */
final class ApiResponse
{
    /**
     * Respuesta exitosa con el envelope estándar.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        $payload = [
            'data' => $data,
            'message' => $message,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Respuesta de error con el envelope estándar.
     *
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>|null  $meta
     */
    public static function error(
        string $message = 'Ha ocurrido un error.',
        ?array $errors = null,
        int $status = 400,
        mixed $data = null,
        ?array $meta = null,
    ): JsonResponse {
        $payload = [
            'data' => $data,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Respuesta paginada: envuelve los items en `data` y expone la
     * información de paginación en `meta` (incluye total y last_page
     * según las convenciones del diseño).
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'OK',
        int $status = 200,
    ): JsonResponse {
        return self::success(
            data: $paginator->items(),
            message: $message,
            status: $status,
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        );
    }
}

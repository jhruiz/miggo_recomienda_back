<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Trait de conveniencia para controladores que exponen el envelope
 * de respuesta JSON estándar { data, message, errors?, meta? }.
 *
 * Uso:
 *   class ExampleController extends Controller
 *   {
 *       use HasApiResponses;
 *
 *       public function index()
 *       {
 *           return $this->ok($data, 'Listado obtenido.');
 *       }
 *   }
 */
trait HasApiResponses
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    protected function ok(mixed $data = null, string $message = 'OK', ?array $meta = null): JsonResponse
    {
        return ApiResponse::success($data, $message, 200, $meta);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    protected function created(mixed $data = null, string $message = 'Recurso creado.', ?array $meta = null): JsonResponse
    {
        return ApiResponse::success($data, $message, 201, $meta);
    }

    protected function noContent(string $message = 'Sin contenido.'): JsonResponse
    {
        return ApiResponse::success(null, $message, 204);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    protected function error(string $message, ?array $errors = null, int $status = 400): JsonResponse
    {
        return ApiResponse::error($message, $errors, $status);
    }

    protected function paginated(LengthAwarePaginator $paginator, string $message = 'OK'): JsonResponse
    {
        return ApiResponse::paginated($paginator, $message);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Responses\HasApiResponses;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\NotificationDataHydrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centro de notificaciones del Usuario (/api/v1/notifications) — tarea 14.2b
 * (Requisitos 12.3, 12.4, 12.5, 12.7).
 *
 * Todos los endpoints requieren autenticación (auth:sanctum) y operan
 * exclusivamente sobre las notificaciones del propio Usuario autenticado:
 *   - GET /notifications             Lista las notificaciones (fecha desc, máx 200).
 *   - GET /notifications/unread-count Contador de no leídas (display "99+" si > 100).
 *   - PUT /notifications/{id}/read   Marca una notificación propia como leída.
 *   - PUT /notifications/read-all    Marca todas las no leídas como leídas.
 *
 * El aislamiento por usuario se garantiza en el servicio (todas las consultas
 * filtran por `user_id`), de modo que un Usuario nunca puede leer ni marcar
 * notificaciones ajenas: marcar una notificación que no es suya devuelve 404.
 */
class NotificationController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly NotificationService $service)
    {
    }

    /**
     * GET /api/v1/notifications  (auth:sanctum)
     *
     * Lista las notificaciones del Usuario autenticado ordenadas por fecha de
     * creación descendente, acotadas a 200 (Requisito 12.3). Cada notificación
     * incluye su estado leída/no leída.
     */
    public function index(Request $request): JsonResponse
    {
        $list = $this->service->listFor($request->user()->id);
        $hydrated = (new NotificationDataHydrator())->hydrate($list, $request->user()->id);

        $notifications = $list
            ->map(fn (Notification $notification) => array_merge(
                $this->present($notification),
                ['data' => $hydrated[$notification->id] ?? $notification->data],
            ))
            ->all();

        return $this->ok(
            data: ['notifications' => $notifications],
            message: 'Listado de notificaciones.',
        );
    }

    /**
     * GET /api/v1/notifications/unread-count  (auth:sanctum)
     *
     * Devuelve el número de notificaciones no leídas del Usuario autenticado
     * junto con su valor de presentación: "99+" cuando supera 100 (Requisito
     * 12.5).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $unread = $this->service->unreadCount($request->user()->id);

        return $this->ok(
            data: $unread,
            message: 'Contador de notificaciones no leídas.',
        );
    }

    /**
     * PUT /api/v1/notifications/{id}/read  (auth:sanctum)
     *
     * Marca una notificación propia como leída (Requisito 12.4). Si la
     * notificación no existe o no pertenece al Usuario autenticado, responde
     * 404 (no se revela la existencia de notificaciones ajenas).
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = $this->service->markAsRead($request->user()->id, $id);

        if ($notification === null) {
            return $this->error('Notificación no encontrada.', status: 404);
        }

        return $this->ok(
            data: ['notification' => $this->present($notification)],
            message: 'Notificación marcada como leída.',
        );
    }

    /**
     * PUT /api/v1/notifications/read-all  (auth:sanctum)
     *
     * Marca como leídas todas las notificaciones no leídas del Usuario
     * autenticado (Requisito 12.7).
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $this->service->markAllAsRead($request->user()->id);

        return $this->ok(
            data: ['updated' => $updated],
            message: 'Todas las notificaciones se marcaron como leídas.',
        );
    }

    /**
     * Representación de una Notificación para la API.
     *
     * @return array<string, mixed>
     */
    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'is_read' => $notification->is_read,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gestor_Notificaciones del dominio (tarea 14.1, Requisitos 12.1, 12.2, 12.6).
 *
 * Punto único de emisión de notificaciones in-app. Cada evento relevante de los
 * módulos (postulación, Cotización, Trabajo, Calificación, Chat) llega aquí a
 * través de los adaptadores de los seams de notificación
 * (NotifyingQuoteRequestNotifier, NotifyingQuoteNotifier, NotifyingJobNotifier)
 * y de RatingService.
 *
 * notify() hace dos cosas, en este orden:
 *   1. Persiste la notificación in-app en la tabla `notifications` (fuente de
 *      verdad del centro de notificaciones, tarea 14.2b) — Requisito 12.1.
 *   2. Despacha SendNotificationJob a la cola para transmitir el evento por
 *      Reverb de forma asíncrona, sin bloquear la operación de negocio
 *      (Requisito 12.2) y con la política de reintentos del Job (Requisito
 *      12.6).
 *
 * Persistir primero garantiza que la notificación exista aunque el broadcast
 * falle y se descarte tras los reintentos: el Usuario la verá igualmente al
 * abrir el centro de notificaciones.
 */
class NotificationService
{
    /**
     * Tope de notificaciones devueltas por el centro de notificaciones
     * (Requisito 12.3). Se listan las más recientes hasta este máximo.
     */
    public const LIST_LIMIT = 200;

    /**
     * Umbral de no leídas por encima del cual el contador se muestra como
     * "99+" (Requisito 12.5).
     */
    public const UNREAD_DISPLAY_CAP = 100;

    /**
     * Emite una notificación in-app para un Usuario (Requisito 12.1): inserta
     * la fila y despacha el envío asíncrono por Reverb (Requisito 12.2).
     *
     * @param  int  $userId  Usuario destinatario de la notificación.
     * @param  string  $type  Tipo/evento de la notificación (p. ej.
     *                        `quote_request.application_received`).
     * @param  array<string, mixed>  $data  Carga de datos de la notificación.
     */
    public function notify(int $userId, string $type, array $data = []): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'data' => $data,
            'is_read' => false,
            'read_at' => null,
        ]);

        // Envío asíncrono por cola (Requisito 12.2) con reintentos (12.6). Se
        // referencia por id para que el Job lea el estado fresco en ejecución.
        SendNotificationJob::dispatch($notification->id);

        return $notification;
    }

    /**
     * Emite la misma notificación a varios destinatarios (p. ej. todos los
     * Prestadores que postularon a una Solicitud editada o cerrada).
     *
     * @param  iterable<int>  $userIds  Usuarios destinatarios.
     * @param  array<string, mixed>  $data  Carga de datos de la notificación.
     * @return array<int, Notification>
     */
    public function notifyMany(iterable $userIds, string $type, array $data = []): array
    {
        $notifications = [];

        foreach ($userIds as $userId) {
            $notifications[] = $this->notify((int) $userId, $type, $data);
        }

        return $notifications;
    }

    /**
     * Lista las notificaciones de un Usuario para el centro de notificaciones
     * (Requisito 12.3): ordenadas por fecha de creación descendente (más
     * recientes primero) y acotadas a {@see self::LIST_LIMIT} filas.
     *
     * Cada fila conserva su estado leída/no leída (`is_read`, `read_at`) para
     * que el cliente pueda distinguirlas (Requisito 12.3).
     *
     * @return Collection<int, Notification>
     */
    public function listFor(int $userId): Collection
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * Número de notificaciones no leídas de un Usuario (Requisito 12.5).
     *
     * Devuelve el conteo numérico real junto con un valor de presentación: si
     * supera {@see self::UNREAD_DISPLAY_CAP} se expone "99+", en otro caso el
     * número como cadena. Así el cliente puede mostrar el badge sin recalcular
     * la regla de tope.
     *
     * @return array{count: int, display: string}
     */
    public function unreadCount(int $userId): array
    {
        $count = Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return [
            'count' => $count,
            'display' => $count > self::UNREAD_DISPLAY_CAP ? '99+' : (string) $count,
        ];
    }

    /**
     * Marca como leída una notificación propia del Usuario (Requisito 12.4):
     * fija `is_read` en true y `read_at` en el instante actual.
     *
     * Solo opera sobre notificaciones del propio Usuario: si la notificación
     * no existe o no le pertenece devuelve null, para que el controlador
     * responda 404 sin revelar la existencia de notificaciones ajenas.
     */
    public function markAsRead(int $userId, int $notificationId): ?Notification
    {
        $notification = Notification::query()
            ->where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($notification === null) {
            return null;
        }

        if (! $notification->is_read) {
            $notification->forceFill([
                'is_read' => true,
                'read_at' => now(),
            ])->save();
        }

        return $notification;
    }

    /**
     * Marca como leídas todas las notificaciones no leídas del Usuario
     * (Requisito 12.7). Devuelve cuántas filas se actualizaron.
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }
}

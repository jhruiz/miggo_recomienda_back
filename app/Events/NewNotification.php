<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de broadcasting emitido al entregar una notificación in-app nueva a un
 * Usuario (tarea 14.1, Requisitos 12.1, 12.2).
 *
 * Lo transmite SendNotificationJob desde la cola (Requisito 12.2: envío
 * asíncrono) por Laravel Reverb al canal privado `private-user.{userId}`, al
 * que solo el propio Usuario puede suscribirse según la autorización de
 * routes/channels.php (`user.{userId}`).
 *
 * El payload incluye los campos que el centro de notificaciones del frontend
 * (tarea 14.4) necesita para renderizar la notificación en tiempo real sin una
 * petición adicional al servidor.
 */
class NewNotification implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Notification $notification)
    {
    }

    /**
     * Canal privado del Usuario destinatario sobre el que se transmite.
     *
     * Nota: Laravel antepone el prefijo `private-`, por lo que el nombre lógico
     * `user.{userId}` se publica como `private-user.{userId}`, coincidiendo con
     * la autorización de routes/channels.php y la suscripción del frontend.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->notification->user_id),
        ];
    }

    /**
     * Nombre del evento transmitido (lo escucha el frontend).
     */
    public function broadcastAs(): string
    {
        return 'notification.new';
    }

    /**
     * Carga útil transmitida al suscriptor del canal.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'data' => $this->notification->data,
            'is_read' => $this->notification->is_read,
            'created_at' => optional($this->notification->created_at)->toIso8601String(),
        ];
    }
}

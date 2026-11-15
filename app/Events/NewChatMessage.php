<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de broadcasting emitido al persistir un mensaje nuevo en un canal de
 * Chat (tarea 13.3, Requisito 11.4).
 *
 * Se transmite por Laravel Reverb al canal privado `private-chat.{channelId}`,
 * al que solo los participantes (Cliente y Prestador) pueden suscribirse según
 * la autorización definida en routes/channels.php (`chat.{channelId}`).
 *
 * El payload incluye los campos del mensaje que el frontend necesita para
 * renderizar en tiempo real sin una petición adicional al servidor.
 */
class NewChatMessage implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly ChatMessage $message)
    {
    }

    /**
     * Canal privado del Chat sobre el que se transmite el evento.
     *
     * Nota: Laravel antepone el prefijo `private-`, por lo que el nombre lógico
     * `chat.{channelId}` se publica como `private-chat.{channelId}`, coincidiendo
     * con la autorización de routes/channels.php y la suscripción del frontend.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->message->channel_id),
        ];
    }

    /**
     * Nombre del evento transmitido (lo escucha el frontend).
     */
    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /**
     * Carga útil transmitida a los suscriptores del canal.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'channel_id' => $this->message->channel_id,
            'sender_user_id' => $this->message->sender_user_id,
            'content' => $this->message->content,
            'sent_at_utc' => optional($this->message->sent_at_utc)->toIso8601String(),
            'created_at' => optional($this->message->created_at)->toIso8601String(),
        ];
    }
}

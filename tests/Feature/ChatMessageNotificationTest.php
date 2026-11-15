<?php

namespace Tests\Feature;

use App\Events\NewChatMessage;
use App\Jobs\SendNotificationJob;
use App\Models\ChatChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests para la tarea 13.6 (Requisito 11.9): al enviar un mensaje de Chat se
 * notifica al receptor (el OTRO participante, nunca el emisor) a través del
 * Gestor_Notificaciones.
 *
 * Cubre:
 *  - El receptor recibe una notificación `chat.message_received` con el
 *    channel_id y message_id correctos.
 *  - El emisor NUNCA se notifica a sí mismo.
 *  - La notificación se emite tanto si envía el Cliente como si envía el
 *    Prestador (apunta siempre al otro participante).
 *  - El envío asíncrono se delega a la cola (SendNotificationJob).
 */
class ChatMessageNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeChannel(
        User $client,
        User $provider,
        string $status = ChatChannel::STATUS_ACTIVO,
    ): ChatChannel {
        return ChatChannel::create([
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => $status,
        ]);
    }

    public function test_enviar_mensaje_notifica_al_receptor_prestador(): void
    {
        Event::fake([NewChatMessage::class]);
        Queue::fake();

        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($client);

        $response = $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => 'Hola, ¿sigue disponible?'],
        )->assertCreated();

        $messageId = $response->json('data.message.id');

        // El receptor es el prestador (el otro participante), no el emisor.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $provider->id,
            'type' => 'chat.message_received',
        ]);

        // El emisor nunca se notifica a sí mismo.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $client->id,
            'type' => 'chat.message_received',
        ]);

        $notification = \App\Models\Notification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'chat.message_received')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame($channel->id, $notification->data['channel_id']);
        $this->assertSame($messageId, $notification->data['message_id']);
        $this->assertSame($client->id, $notification->data['sender_user_id']);

        // El envío por Reverb se delega a la cola de forma asíncrona.
        Queue::assertPushed(SendNotificationJob::class);
    }

    public function test_enviar_mensaje_del_prestador_notifica_al_cliente(): void
    {
        Event::fake([NewChatMessage::class]);
        Queue::fake();

        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($provider);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => 'Sí, cuénteme más detalles.'],
        )->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $client->id,
            'type' => 'chat.message_received',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $provider->id,
            'type' => 'chat.message_received',
        ]);
    }
}

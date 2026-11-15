<?php

namespace Tests\Feature;

use App\Events\NewChatMessage;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests para la tarea 13.3 (Gestor_Chat): envío, persistencia e
 * historial de mensajes de Chat (Requisitos 11.4, 11.5, 11.6, 11.7, 11.8).
 *
 * Cubre:
 *  - 11.4/11.5: enviar persiste (canal, emisor, contenido, sent_at_utc) y
 *    transmite el evento NewChatMessage por Reverb.
 *  - 11.8: contenido vacío o >2000 caracteres se rechaza con 422 sin persistir.
 *  - 11.7: enviar a un canal "cerrado" se rechaza con 409 sin persistir.
 *  - 403: un tercero (no participante) no puede enviar ni leer.
 *  - 11.6: el historial se devuelve cronológicamente, en páginas de 50.
 *  - Listado de canales del usuario.
 */
class ChatMessageTest extends TestCase
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

    // --- Requisitos 11.4 / 11.5: enviar persiste y transmite --------------

    public function test_participante_envia_mensaje_persiste_y_transmite(): void
    {
        Event::fake([NewChatMessage::class]);

        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($client);

        $response = $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => 'Hola, ¿sigue disponible?'],
        );

        $response->assertCreated()
            ->assertJsonPath('data.message.channel_id', $channel->id)
            ->assertJsonPath('data.message.sender_user_id', $client->id)
            ->assertJsonPath('data.message.content', 'Hola, ¿sigue disponible?');

        $this->assertNotNull($response->json('data.message.sent_at_utc'));

        $this->assertDatabaseHas('chat_messages', [
            'channel_id' => $channel->id,
            'sender_user_id' => $client->id,
            'content' => 'Hola, ¿sigue disponible?',
        ]);

        Event::assertDispatched(
            NewChatMessage::class,
            fn (NewChatMessage $event) => $event->message->channel_id === $channel->id
                && $event->message->sender_user_id === $client->id,
        );
    }

    public function test_contenido_se_recorta_antes_de_persistir(): void
    {
        Event::fake([NewChatMessage::class]);

        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($provider);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => '   hola   '],
        )->assertCreated()
            ->assertJsonPath('data.message.content', 'hola');
    }

    // --- Requisito 11.8: validación de contenido 1-2000 (422 sin persistir) --

    public function test_contenido_vacio_se_rechaza_con_422_sin_persistir(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($client);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => '   '],
        )->assertStatus(422)
            ->assertJsonValidationErrors('content');

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_contenido_excesivo_se_rechaza_con_422_sin_persistir(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($client);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => str_repeat('a', 2001)],
        )->assertStatus(422)
            ->assertJsonValidationErrors('content');

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_contenido_de_2000_caracteres_es_aceptado(): void
    {
        Event::fake([NewChatMessage::class]);

        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($client);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => str_repeat('a', 2000)],
        )->assertCreated();

        $this->assertDatabaseCount('chat_messages', 1);
    }

    // --- Requisito 11.7: canal "cerrado" rechaza con 409 sin persistir -----

    public function test_canal_cerrado_rechaza_envio_con_409_sin_persistir(): void
    {
        Event::fake([NewChatMessage::class]);

        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider, ChatChannel::STATUS_CERRADO);

        Sanctum::actingAs($client);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => 'Mensaje a canal cerrado'],
        )->assertStatus(409);

        $this->assertDatabaseCount('chat_messages', 0);
        Event::assertNotDispatched(NewChatMessage::class);
    }

    public function test_canal_pre_aceptacion_admite_mensajes(): void
    {
        Event::fake([NewChatMessage::class]);

        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider, ChatChannel::STATUS_PRE_ACEPTACION);

        Sanctum::actingAs($provider);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => 'Consulta previa'],
        )->assertCreated();

        $this->assertDatabaseCount('chat_messages', 1);
    }

    // --- 403: un tercero no participante no puede enviar ni leer -----------

    public function test_tercero_no_puede_enviar_mensaje(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $outsider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($outsider);

        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => 'No debería poder'],
        )->assertStatus(403);

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_tercero_no_puede_leer_historial(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $outsider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/chat/channels/{$channel->id}/messages")
            ->assertStatus(403);
    }

    // --- Requisito 11.6: historial cronológico paginado por 50 -------------

    public function test_historial_ordenado_cronologicamente(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        foreach (['uno', 'dos', 'tres'] as $content) {
            ChatMessage::create([
                'channel_id' => $channel->id,
                'sender_user_id' => $client->id,
                'content' => $content,
                'sent_at_utc' => now(),
            ]);
        }

        Sanctum::actingAs($provider);

        $response = $this->getJson("/api/v1/chat/channels/{$channel->id}/messages")
            ->assertOk();

        $contents = array_column($response->json('data.messages'), 'content');
        $this->assertSame(['uno', 'dos', 'tres'], $contents);
    }

    public function test_historial_pagina_en_bloques_de_50(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        for ($i = 1; $i <= 60; $i++) {
            ChatMessage::create([
                'channel_id' => $channel->id,
                'sender_user_id' => $client->id,
                'content' => "msg {$i}",
                'sent_at_utc' => now(),
            ]);
        }

        Sanctum::actingAs($client);

        $first = $this->getJson("/api/v1/chat/channels/{$channel->id}/messages")
            ->assertOk();

        $this->assertCount(50, $first->json('data.messages'));
        $this->assertTrue($first->json('meta.has_more'));
        $this->assertSame('msg 1', $first->json('data.messages.0.content'));

        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson(
            "/api/v1/chat/channels/{$channel->id}/messages?cursor={$cursor}",
        )->assertOk();

        $this->assertCount(10, $second->json('data.messages'));
        $this->assertFalse($second->json('meta.has_more'));
        $this->assertSame('msg 51', $second->json('data.messages.0.content'));
    }

    // --- Listado de canales del usuario ------------------------------------

    public function test_listado_de_canales_del_usuario(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->makeChannel($client, $provider);
        // Canal en el que el cliente no participa.
        $this->makeChannel($other, $provider);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/v1/chat/channels')->assertOk();

        $ids = array_column($response->json('data.channels'), 'id');
        $this->assertSame([$mine->id], $ids);
    }

    public function test_endpoints_requieren_autenticacion(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = $this->makeChannel($client, $provider);

        $this->getJson('/api/v1/chat/channels')->assertUnauthorized();
        $this->getJson("/api/v1/chat/channels/{$channel->id}/messages")->assertUnauthorized();
        $this->postJson(
            "/api/v1/chat/channels/{$channel->id}/messages",
            ['content' => 'hola'],
        )->assertUnauthorized();
    }
}

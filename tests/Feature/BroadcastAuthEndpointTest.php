<?php

namespace Tests\Feature;

use App\Models\ChatChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Autorización de canales privados para la SPA (Echo/Reverb).
 *
 * El frontend corre en otro origen y se autentica con token Bearer de Sanctum,
 * así que la autorización debe vivir en /api/broadcasting/auth con
 * `auth:sanctum` (no en /broadcasting/auth con la sesión web). Sin esto la
 * suscripción a `private-chat.{id}` falla y los mensajes no llegan en tiempo
 * real al otro participante.
 */
class BroadcastAuthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml usa el broadcaster "null", que autoriza todo. Se usa el
        // de Reverb (firma local, sin red) y se re-registran los canales sobre él.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    private function channel(User $client, User $provider): ChatChannel
    {
        return ChatChannel::create([
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);
    }

    /** @return array<string, string> */
    private function payload(ChatChannel $channel): array
    {
        return ['socket_id' => '1234.5678', 'channel_name' => 'private-chat.'.$channel->id];
    }

    public function test_la_ruta_vive_bajo_api_con_auth_sanctum(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/broadcasting/auth', 'POST'));

        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
    }

    public function test_sin_token_responde_401(): void
    {
        $channel = $this->channel(User::factory()->create(), User::factory()->create());

        $this->postJson('/api/broadcasting/auth', $this->payload($channel))->assertStatus(401);
    }

    public function test_participante_con_token_obtiene_la_firma(): void
    {
        $client = User::factory()->create();
        $channel = $this->channel($client, User::factory()->create());
        Sanctum::actingAs($client);

        $this->postJson('/api/broadcasting/auth', $this->payload($channel))
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_no_participante_recibe_403(): void
    {
        $channel = $this->channel(User::factory()->create(), User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/broadcasting/auth', $this->payload($channel))->assertStatus(403);
    }
}
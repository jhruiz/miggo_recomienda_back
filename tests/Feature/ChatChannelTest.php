<?php

namespace Tests\Feature;

use App\Models\ChatChannel;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestApplication;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobService;
use App\Services\QuoteRequestService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Feature tests para la tarea 13.1 (Gestor_Chat): creación y transición de
 * estado de canales de Chat y autorización de los canales privados
 * (Requisitos 11.1, 11.2, 11.3).
 *
 * Cubre:
 *  - 11.1: al crear un Trabajo se habilita un canal "activo" Cliente↔Prestador.
 *  - 11.2: al postular un Prestador se habilita un canal "pre_aceptacion".
 *  - 11.3: al cerrar la Solicitud se cierran los canales "pre_aceptacion".
 *  - Autorización de `user.{userId}` y `chat.{channelId}` en routes/channels.php:
 *    acepta a los participantes y rechaza al resto.
 */
class ChatChannelTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug = 'plomeria'): ServiceCategory
    {
        return ServiceCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug)],
        );
    }

    private function jobService(): JobService
    {
        return app(JobService::class);
    }

    private function quoteRequestService(): QuoteRequestService
    {
        return app(QuoteRequestService::class);
    }

    private function makeQuote(User $client, User $provider, int $deliveryDays = 10): Quote
    {
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Instalación eléctrica',
            'description' => 'Instalar tomas en la cocina',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        return Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio completo',
            'total_price' => 302.00,
            'delivery_days' => $deliveryDays,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);
    }

    private function openRequest(User $owner): QuoteRequest
    {
        return QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Instalación',
            'description' => 'Instalar algo',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);
    }

    // --- Requisito 11.1: canal "activo" al crear un Trabajo ----------------

    public function test_crear_trabajo_habilita_canal_activo_entre_las_partes(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();

        $job = $this->jobService()->createJob($this->makeQuote($client, $provider));

        $this->assertDatabaseHas('chat_channels', [
            'job_id' => $job->id,
            'quote_request_application_id' => null,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);
    }

    public function test_crear_trabajo_es_idempotente_para_el_canal(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $quote = $this->makeQuote($client, $provider);

        $this->jobService()->createJob($quote);
        $this->jobService()->createJob($quote->refresh());

        $this->assertDatabaseCount('chat_channels', 1);
    }

    // --- Requisito 11.2: canal "pre_aceptacion" al postular ----------------

    public function test_postular_habilita_canal_pre_aceptacion(): void
    {
        $owner = User::factory()->create();
        $provider = User::factory()->create();
        $request = $this->openRequest($owner);

        $application = $this->quoteRequestService()->apply($request, $provider);

        $this->assertDatabaseHas('chat_channels', [
            'quote_request_application_id' => $application->id,
            'job_id' => null,
            'client_user_id' => $owner->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_PRE_ACEPTACION,
        ]);
    }

    // --- Requisito 11.3: cerrar la Solicitud cierra los canales ------------

    public function test_cerrar_solicitud_cierra_canales_pre_aceptacion(): void
    {
        $owner = User::factory()->create();
        $providerA = User::factory()->create();
        $providerB = User::factory()->create();
        $request = $this->openRequest($owner);

        $this->quoteRequestService()->apply($request, $providerA);
        $this->quoteRequestService()->apply($request, $providerB);

        $this->quoteRequestService()->close($request->refresh());

        $this->assertSame(
            2,
            ChatChannel::where('status', ChatChannel::STATUS_CERRADO)->count(),
        );
        $this->assertSame(
            0,
            ChatChannel::where('status', ChatChannel::STATUS_PRE_ACEPTACION)->count(),
        );
    }

    public function test_cerrar_canal_pre_aceptacion_por_rechazo_de_postulacion(): void
    {
        $owner = User::factory()->create();
        $provider = User::factory()->create();
        $request = $this->openRequest($owner);

        $application = $this->quoteRequestService()->apply($request, $provider);

        app(\App\Services\ChatService::class)->closePreAcceptanceChannelForApplication($application);

        $this->assertDatabaseHas('chat_channels', [
            'quote_request_application_id' => $application->id,
            'status' => ChatChannel::STATUS_CERRADO,
        ]);
    }

    // --- Autorización de canales privados (routes/channels.php) ------------

    /**
     * Reconstruye un broadcaster de pruebas con las definiciones de canal reales
     * cargadas desde routes/channels.php, exponiendo la verificación de acceso
     * sin depender de ningún driver concreto (reverb/pusher).
     */
    private function makeAuthorizer(): object
    {
        $registered = Broadcast::getChannels();

        $broadcaster = new class extends Broadcaster
        {
            public function authorize(Request $request, string $channel): bool
            {
                return $this->verifyUserCanAccessChannel($request, $channel) === true;
            }

            public function auth($request)
            {
                //
            }

            public function validAuthenticationResponse($request, $result)
            {
                return true;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                //
            }
        };

        foreach ($registered as $pattern => $callback) {
            $broadcaster->channel($pattern, $callback);
        }

        return $broadcaster;
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/broadcasting/auth', 'POST');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_canal_user_autoriza_solo_al_propio_usuario(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $authorizer = $this->makeAuthorizer();

        $this->assertTrue(
            $authorizer->authorize($this->requestFor($user), "user.{$user->id}"),
        );

        $this->expectException(AccessDeniedHttpException::class);
        $authorizer->authorize($this->requestFor($other), "user.{$user->id}");
    }

    public function test_canal_chat_autoriza_al_cliente_participante(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = ChatChannel::create([
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);

        $authorizer = $this->makeAuthorizer();

        $this->assertTrue(
            $authorizer->authorize($this->requestFor($client), "chat.{$channel->id}"),
        );
    }

    public function test_canal_chat_autoriza_al_prestador_participante(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $channel = ChatChannel::create([
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);

        $authorizer = $this->makeAuthorizer();

        $this->assertTrue(
            $authorizer->authorize($this->requestFor($provider), "chat.{$channel->id}"),
        );
    }

    public function test_canal_chat_rechaza_a_un_tercero(): void
    {
        $client = User::factory()->create();
        $provider = User::factory()->create();
        $outsider = User::factory()->create();
        $channel = ChatChannel::create([
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);

        $authorizer = $this->makeAuthorizer();

        $this->expectException(AccessDeniedHttpException::class);
        $authorizer->authorize($this->requestFor($outsider), "chat.{$channel->id}");
    }
}

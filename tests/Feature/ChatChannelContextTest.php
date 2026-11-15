<?php

namespace Tests\Feature;

use App\Models\ChatChannel;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * GET /chat/channels expone el contexto de cada conversación (Solicitud de
 * origen y nombres de las partes) para poder distinguirlas en la UI, sobre
 * todo desde el punto de vista del Prestador con varias postulaciones.
 */
class ChatChannelContextTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
    }

    public function test_canal_de_postulacion_incluye_la_solicitud_y_las_partes(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $request = $this->openRequest($client, 'Pintar sala');
        $application = $request->applications()->create([
            'provider_user_id' => $provider->id,
            'applied_at' => now(),
        ]);
        ChatChannel::create([
            'quote_request_application_id' => $application->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_PRE_ACEPTACION,
        ]);
        Sanctum::actingAs($provider);

        $channel = $this->getJson('/api/v1/chat/channels')->assertOk()->json('data.channels.0');

        $this->assertSame('postulacion', $channel['context']);
        $this->assertSame($client->name, $channel['client_name']);
        $this->assertSame($provider->name, $channel['provider_name']);
        $this->assertSame($request->id, $channel['quote_request']['id']);
        $this->assertSame('Pintar sala', $channel['quote_request']['title']);
        $this->assertSame('abierta', $channel['quote_request']['status']);
        $this->assertSame('Plomeria', $channel['quote_request']['category_name']);
        $this->assertNull($channel['job_status']);
    }

    public function test_canal_de_trabajo_incluye_la_solicitud_via_la_cotizacion(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest(null, 'Instalar tomas');
        $job = $this->makeJob($this->makeQuote($provider, $request, Quote::STATUS_ACEPTADA));
        ChatChannel::create([
            'job_id' => $job->id,
            'client_user_id' => $job->client_user_id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);
        Sanctum::actingAs($provider);

        $channel = $this->getJson('/api/v1/chat/channels')->assertOk()->json('data.channels.0');

        $this->assertSame('trabajo', $channel['context']);
        $this->assertSame('Instalar tomas', $channel['quote_request']['title']);
        $this->assertSame($job->status, $channel['job_status']);
        $this->assertSame($job->quote_id, $channel['quote_id']);
    }

    public function test_trabajo_de_cotizacion_directa_no_tiene_solicitud(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => null,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => 'Directa',
            'total_price' => 100,
            'delivery_days' => 3,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_ACEPTADA,
        ]);
        $job = $this->makeJob($quote);
        ChatChannel::create([
            'job_id' => $job->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);
        Sanctum::actingAs($client);

        $channel = $this->getJson('/api/v1/chat/channels')->assertOk()->json('data.channels.0');

        $this->assertSame('trabajo', $channel['context']);
        $this->assertNull($channel['quote_request']);
        $this->assertSame($provider->name, $channel['provider_name']);
    }
}
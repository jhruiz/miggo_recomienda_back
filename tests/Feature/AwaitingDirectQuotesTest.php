<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * Vista "awaiting" de GET /quotes/received (cotizaciones directas sin respuesta).
 *
 * **Validates: Requirements 1.1–1.6, 2.1–2.6, 3.1–3.4, 4.1–4.3**
 */
class AwaitingDirectQuotesTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
    }

    private function makeDirectQuote(
        User $client,
        User $provider,
        string $status = Quote::STATUS_SOLICITADA,
        string $description = QuoteService::DIRECT_REQUEST_DEFAULT_MESSAGE,
        ?string $createdAt = null,
    ): Quote {
        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => null,
            'service_category_id' => $this->category()->id,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => $description,
            'total_price' => 0,
            'delivery_days' => 0,
            'expires_at' => now()->addDays(7),
            'status' => $status,
        ]);

        if ($createdAt !== null) {
            $quote->forceFill(['created_at' => $createdAt])->save();
        }

        return $quote;
    }

    /**
     * @return list<int>
     */
    private function ids(string $query, ?User $as = null): array
    {
        if ($as !== null) {
            Sanctum::actingAs($as);
        }

        return collect($this->getJson('/api/v1/quotes/received'.$query)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_vista_por_defecto_excluye_directas_sin_responder_y_no_trae_message_excerpt(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $this->makeDirectQuote($client, $provider, Quote::STATUS_SOLICITADA);
        $this->makeDirectQuote($client, $provider, Quote::STATUS_SIN_RESPUESTA);
        $pending = $this->makeDirectQuote($client, $provider, Quote::STATUS_PENDIENTE);
        Sanctum::actingAs($client);

        $default = $this->getJson('/api/v1/quotes/received')->assertOk();
        $explicit = $this->getJson('/api/v1/quotes/received?view=received')->assertOk();

        $this->assertSame([$pending->id], collect($default->json('data'))->pluck('id')->all());
        $this->assertArrayNotHasKey('message_excerpt', $default->json('data.0'));
        $this->assertSame($default->json('data'), $explicit->json('data'));
    }

    public function test_awaiting_devuelve_solo_directas_solicitada_o_sin_respuesta_del_cliente_mas_recientes_primero(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $oldest = $this->makeDirectQuote($client, $provider, Quote::STATUS_SOLICITADA, createdAt: now()->subDays(3)->toDateTimeString());
        $newest = $this->makeDirectQuote($client, $provider, Quote::STATUS_SOLICITADA, createdAt: now()->subDay()->toDateTimeString());
        $expired = $this->makeDirectQuote($client, $provider, Quote::STATUS_SIN_RESPUESTA, createdAt: now()->subDays(2)->toDateTimeString());
        $this->makeDirectQuote($client, $provider, Quote::STATUS_PENDIENTE);
        $this->makeDirectQuote($client, $provider, Quote::STATUS_ACEPTADA);
        $this->makeQuote($provider, null, Quote::STATUS_PENDIENTE, $client);

        $this->assertSame(
            [$newest->id, $expired->id, $oldest->id],
            $this->ids('?view=awaiting', $client),
        );
    }

    public function test_awaiting_aisla_clientes_y_oculta_la_solicitud_al_prestador(): void
    {
        $client = $this->client();
        $otherClient = $this->client();
        $provider = $this->provider();
        $mine = $this->makeDirectQuote($client, $provider);
        $theirs = $this->makeDirectQuote($otherClient, $provider);

        $this->assertSame([$mine->id], $this->ids('?view=awaiting', $client));
        $this->assertSame([$theirs->id], $this->ids('?view=awaiting', $otherClient));
        $this->assertSame([], $this->ids('?view=awaiting', $provider));
    }

    public function test_awaiting_filtra_por_status_y_trata_status_vacio_como_ausente(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $waiting = $this->makeDirectQuote($client, $provider, Quote::STATUS_SOLICITADA);
        $expired = $this->makeDirectQuote($client, $provider, Quote::STATUS_SIN_RESPUESTA);

        $this->assertSame([$waiting->id], $this->ids('?view=awaiting&status=solicitada', $client));
        $this->assertSame([$expired->id], $this->ids('?view=awaiting&status=sin_respuesta', $client));
        $this->assertEqualsCanonicalizing(
            [$waiting->id, $expired->id],
            $this->ids('?view=awaiting&status=', $client),
        );
    }

    public function test_awaiting_valida_view_status_y_per_page(): void
    {
        Sanctum::actingAs($this->client());

        $this->getJson('/api/v1/quotes/received?view=foo')
            ->assertStatus(422)->assertJsonValidationErrors('view');
        $this->getJson('/api/v1/quotes/received?view=awaiting&status=pendiente')
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/quotes/received?view=awaiting&per_page=0')
            ->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_message_excerpt_refleja_mensaje_truncado_o_nulo_por_defecto(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $own = $this->makeDirectQuote($client, $provider, description: 'Necesito arreglar una fuga', createdAt: now()->subDays(3)->toDateTimeString());
        $long = $this->makeDirectQuote($client, $provider, description: str_repeat('a', 200), createdAt: now()->subDays(2)->toDateTimeString());
        $default = $this->makeDirectQuote($client, $provider, createdAt: now()->subDay()->toDateTimeString());
        Sanctum::actingAs($client);

        $data = collect($this->getJson('/api/v1/quotes/received?view=awaiting')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame('Necesito arreglar una fuga', $data[$own->id]['message_excerpt']);
        $this->assertSame(str_repeat('a', 140).'…', $data[$long->id]['message_excerpt']);
        $this->assertArrayHasKey('message_excerpt', $data[$default->id]);
        $this->assertNull($data[$default->id]['message_excerpt']);
    }

    public function test_awaiting_pagina_con_per_page_y_meta_total(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        foreach (range(1, 3) as $i) {
            $this->makeDirectQuote($client, $provider);
        }
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/v1/quotes/received?view=awaiting&per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_awaiting_sin_resultados_devuelve_200_y_lista_vacia(): void
    {
        Sanctum::actingAs($this->client());

        $this->getJson('/api/v1/quotes/received?view=awaiting')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_awaiting_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/quotes/received?view=awaiting')->assertUnauthorized();
    }

    public function test_sent_sigue_sin_cambios(): void
    {
        $provider = $this->provider();
        $client = $this->client();
        $direct = $this->makeDirectQuote($client, $provider, Quote::STATUS_SOLICITADA);
        Sanctum::actingAs($provider);

        $response = $this->getJson('/api/v1/quotes/sent')->assertOk();

        $this->assertSame([$direct->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertArrayNotHasKey('message_excerpt', $response->json('data.0'));
    }
}

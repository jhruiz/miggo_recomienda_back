<?php

namespace Tests\Feature;

use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * Exploración de C7/C8: GET /quotes/sent y GET /quotes/received. Deben FALLAR
 * en código sin corregir (hoy /quotes/sent y /quotes/received dan 404/422).
 *
 * **Validates: Requirements 1.9, 1.10, 1.11, 2.9, 2.10, 2.11**
 */
class MyQuotesListingTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
    }

    public function test_sent_devuelve_solo_las_cotizaciones_del_prestador_autenticado(): void
    {
        $me = $this->provider();
        $other = $this->provider();
        $mine = $this->makeQuote($me);
        $this->makeQuote($other);
        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/quotes/sent')->assertOk();

        $response->assertJsonStructure(['data', 'message', 'meta']);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    public function test_received_devuelve_solo_las_cotizaciones_del_cliente_autenticado(): void
    {
        $client = $this->client();
        $otherClient = $this->client();
        $provider = $this->provider();
        $mine = $this->makeQuote($provider, null, Quote::STATUS_PENDIENTE, $client);
        $this->makeQuote($provider, null, Quote::STATUS_PENDIENTE, $otherClient);
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/v1/quotes/received')->assertOk();

        $response->assertJsonStructure(['data', 'message', 'meta']);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }
}

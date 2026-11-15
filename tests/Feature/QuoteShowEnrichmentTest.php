<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * Exploración de C7: GET /quotes/{id} debe incluir quote_request, job_id y
 * job_status. Debe FALLAR en código sin corregir.
 *
 * **Validates: Requirements 1.10, 2.10**
 */
class QuoteShowEnrichmentTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
    }

    public function test_detalle_de_cotizacion_aceptada_incluye_solicitud_y_trabajo(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest(null, 'Pintar sala');
        $quote = $this->makeQuote($provider, $request, Quote::STATUS_ACEPTADA);
        $job = $this->makeJob($quote);
        Sanctum::actingAs($provider);

        $this->getJson("/api/v1/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.quote.quote_request.id', $request->id)
            ->assertJsonPath('data.quote.quote_request.title', 'Pintar sala')
            ->assertJsonPath('data.quote.job_id', $job->id)
            ->assertJsonPath('data.quote.job_status', Job::STATUS_EN_PROGRESO);
    }
}

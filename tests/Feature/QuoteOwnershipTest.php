<?php

namespace Tests\Feature;

use App\Jobs\ExpireQuoteJob;
use App\Models\Notification;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * Exploración de bug condition C1 (quote): un Prestador dueño de la solicitud
 * no debe poder cotizarla. Debe FALLAR en código sin corregir.
 *
 * **Validates: Requirements 1.3, 2.3**
 */
class QuoteOwnershipTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
    }

    public function test_dueno_prestador_no_puede_cotizar_su_propia_solicitud(): void
    {
        Queue::fake();
        $owner = $this->actingAsProvider();
        $request = $this->openRequest($owner);

        $this->postJson('/api/v1/quotes', $this->validQuotePayload($request))
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes cotizar tu propia solicitud de cotización.');

        $this->assertSame(0, Quote::count());
        $this->assertSame(0, Notification::count());
        Queue::assertNotPushed(ExpireQuoteJob::class);
    }
}

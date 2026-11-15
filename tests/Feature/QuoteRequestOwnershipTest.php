<?php

namespace Tests\Feature;

use App\Models\ChatChannel;
use App\Models\Notification;
use App\Models\QuoteRequestApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * Exploración de bug condition C1 (apply): un Prestador dueño de la solicitud
 * no debe poder postular a ella. Debe FALLAR en código sin corregir.
 *
 * **Validates: Requirements 1.1, 2.1**
 */
class QuoteRequestOwnershipTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
    }

    public function test_dueno_prestador_no_puede_postular_a_su_propia_solicitud(): void
    {
        Queue::fake();
        $owner = $this->actingAsProvider();
        $request = $this->openRequest($owner);

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertStatus(403)
            ->assertJsonPath('message', 'No puedes postular a tu propia solicitud de cotización.');

        $this->assertSame(0, QuoteRequestApplication::count());
        $this->assertSame(0, ChatChannel::count());
        $this->assertSame(0, Notification::count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\QuoteRequestApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * Exploración de C7: GET /quote-requests/my-applications. Debe FALLAR en
 * código sin corregir (la ruta no existe).
 *
 * **Validates: Requirements 1.9, 2.9**
 */
class MyApplicationsListingTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
    }

    public function test_my_applications_devuelve_solo_las_postulaciones_del_usuario(): void
    {
        $me = $this->provider();
        $other = $this->provider();
        $request = $this->openRequest();

        $mine = QuoteRequestApplication::create([
            'quote_request_id' => $request->id,
            'provider_user_id' => $me->id,
            'applied_at' => now(),
        ]);
        QuoteRequestApplication::create([
            'quote_request_id' => $request->id,
            'provider_user_id' => $other->id,
            'applied_at' => now(),
        ]);
        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/quote-requests/my-applications')->assertOk();

        $response->assertJsonStructure(['data', 'message', 'meta']);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }
}

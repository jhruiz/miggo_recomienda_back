<?php

namespace Tests\Feature;

use App\Contracts\JobNotifier;
use App\Contracts\QuoteNotifier;
use App\Contracts\QuoteRequestNotifier;
use App\Events\NewChatMessage;
use App\Models\ChatChannel;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Quote;
use App\Services\RatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\QuoteFlowFixtures;
use Tests\TestCase;

/**
 * Exploración de C5: el payload de las notificaciones debe incluir
 * actor_name y/o quote_request_title conservando las claves previas, y
 * GET /notifications debe hidratar notificaciones antiguas con solo ids.
 * Deben FALLAR en código sin corregir.
 *
 * **Validates: Requirements 1.7, 2.7**
 */
class NotificationPayloadEnrichmentTest extends TestCase
{
    use QuoteFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteFlow();
        Queue::fake();
        Event::fake([NewChatMessage::class]);
    }

    private function lastData(int $userId, string $type): array
    {
        $n = Notification::where('user_id', $userId)->where('type', $type)->latest('id')->first();
        $this->assertNotNull($n, "No se emitió {$type}");

        return $n->data;
    }

    public function test_application_received_incluye_actor_name_y_conserva_claves(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $request = $this->openRequest($client, 'Pintar sala');

        app(QuoteRequestNotifier::class)->notifyClientOfApplication($request, $provider->id);

        $data = $this->lastData($client->id, 'quote_request.application_received');
        $this->assertSame($provider->name, $data['actor_name'] ?? null);
        $this->assertSame($request->id, $data['quote_request_id']);
        $this->assertSame('Pintar sala', $data['title']);
        $this->assertSame($provider->id, $data['provider_user_id']);
    }

    public function test_quote_request_edited_incluye_quote_request_title(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $request = $this->openRequest($client, 'Pintar sala');
        $request->applications()->create(['provider_user_id' => $provider->id, 'applied_at' => now()]);

        app(QuoteRequestNotifier::class)->notifyApplicantsOfEdit($request);

        $data = $this->lastData($provider->id, 'quote_request.edited');
        $this->assertSame('Pintar sala', $data['quote_request_title'] ?? null);
        $this->assertSame('Pintar sala', $data['title']);
    }

    public function test_quote_received_incluye_actor_y_titulo_y_conserva_claves(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest(null, 'Pintar sala');
        $quote = $this->makeQuote($provider, $request);

        app(QuoteNotifier::class)->notifyClientOfNewQuote($quote);

        $data = $this->lastData($quote->client_user_id, 'quote.received');
        $this->assertSame($provider->name, $data['actor_name'] ?? null);
        $this->assertSame('Pintar sala', $data['quote_request_title'] ?? null);
        $this->assertSame($quote->id, $data['quote_id']);
        $this->assertSame($request->id, $data['quote_request_id']);
        $this->assertSame($provider->id, $data['provider_user_id']);
        $this->assertSame($quote->client_user_id, $data['client_user_id'] ?? null);
    }

    public function test_job_completion_requested_incluye_actor_y_titulo(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest(null, 'Pintar sala');
        $job = $this->makeJob($this->makeQuote($provider, $request, Quote::STATUS_ACEPTADA));

        app(JobNotifier::class)->notifyClientOfCompletion($job);

        $data = $this->lastData($job->client_user_id, 'job.completion_requested');
        $this->assertSame($provider->name, $data['actor_name'] ?? null);
        $this->assertSame('Pintar sala', $data['quote_request_title'] ?? null);
        $this->assertSame($job->id, $data['job_id']);
        $this->assertSame($provider->id, $data['provider_user_id']);
    }

    public function test_job_completion_rejected_incluye_titulo_y_conserva_reason(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest(null, 'Pintar sala');
        $job = $this->makeJob($this->makeQuote($provider, $request, Quote::STATUS_ACEPTADA));

        app(JobNotifier::class)->notifyProviderOfRejection($job, 'Falta terminar');

        $data = $this->lastData($provider->id, 'job.completion_rejected');
        $this->assertSame('Pintar sala', $data['quote_request_title'] ?? null);
        $this->assertSame('Falta terminar', $data['reason']);
    }

    public function test_rating_received_incluye_actor_name_y_conserva_claves(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest(null, 'Pintar sala');
        $job = $this->makeJob($this->makeQuote($provider, $request, Quote::STATUS_ACEPTADA), Job::STATUS_COMPLETADO);
        $client = \App\Models\User::findOrFail($job->client_user_id);
        app(RatingService::class)->enableRatings($job);
        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Excelente trabajo, muy profesional.',
        ])->assertCreated();

        $data = $this->lastData($provider->id, 'rating.received');
        $this->assertSame($client->name, $data['actor_name'] ?? null);
        $this->assertSame($job->id, $data['job_id']);
        $this->assertSame($client->id, $data['rater_user_id']);
    }

    public function test_chat_message_received_incluye_actor_name_y_conserva_claves(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $channel = ChatChannel::create([
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);
        Sanctum::actingAs($client);

        $this->postJson("/api/v1/chat/channels/{$channel->id}/messages", ['content' => 'Hola, ¿disponible?'])
            ->assertCreated();

        $data = $this->lastData($provider->id, 'chat.message_received');
        $this->assertSame($client->name, $data['actor_name'] ?? null);
        $this->assertSame($channel->id, $data['channel_id']);
        $this->assertSame($client->id, $data['sender_user_id']);
        $this->assertArrayHasKey('preview', $data);
    }

    public function test_get_notifications_hidrata_notificaciones_antiguas_solo_con_ids(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest(null, 'Pintar sala');
        $quote = $this->makeQuote($provider, $request);
        $client = \App\Models\User::findOrFail($quote->client_user_id);

        Notification::create([
            'user_id' => $client->id,
            'type' => 'quote.received',
            'data' => [
                'quote_id' => $quote->id,
                'quote_request_id' => $request->id,
                'provider_user_id' => $provider->id,
            ],
        ]);
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $data = $response->json('data.notifications.0.data');
        $this->assertSame($provider->name, $data['actor_name'] ?? null);
        $this->assertSame('Pintar sala', $data['quote_request_title'] ?? null);
        $this->assertSame($quote->id, $data['quote_id']);
    }
}

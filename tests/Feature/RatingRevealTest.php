<?php

namespace Tests\Feature;

use App\Jobs\CloseRatingWindowJob;
use App\Jobs\RevealRatingsJob;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\Rating;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\RatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 12.5: revelación de Calificaciones de doble ciego
 * (Requisito 8.5) y programación de los Jobs de revelación/cierre.
 *
 * Reglas cubiertas:
 *  - Si AMBAS partes califican, las dos Calificaciones se vuelven visibles de
 *    inmediato (sin esperar los 14 días).
 *  - Mientras solo una parte califique, ninguna Calificación es visible.
 *  - A los 14 días, RevealRatingsJob revela las Calificaciones ya emitidas aun
 *    si solo una parte calificó.
 *  - RevealRatingsJob es idempotente / no-op si ya se reveló (ambas partes
 *    calificaron antes de los 14 días): la "cancelación" por guarda de estado.
 *  - enableRatings programa RevealRatingsJob (+14d) y CloseRatingWindowJob (+30d).
 */
class RatingRevealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    private function category(string $slug = 'plomeria'): ServiceCategory
    {
        return ServiceCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug)],
        );
    }

    private function client(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        return $user;
    }

    private function provider(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');
        $user->assignRole('prestador');

        return $user;
    }

    private function makeJob(User $client, User $provider): Job
    {
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Instalación eléctrica',
            'description' => 'Instalar tomas en la cocina',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio completo',
            'total_price' => 302.00,
            'delivery_days' => 10,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);

        return Job::create([
            'quote_id' => $quote->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => Job::STATUS_COMPLETADO,
            'start_date' => Carbon::today(),
            'estimated_delivery_date' => Carbon::today()->addDays(10),
            'actual_completion_date' => Carbon::today(),
        ]);
    }

    private function service(): RatingService
    {
        return app(RatingService::class);
    }

    private function submit(User $rater, Job $job, int $score, string $comment): void
    {
        $this->service()->submitRating($rater, $job, $score, $comment);
    }

    // --- Requisito 8.5: revelación inmediata cuando ambos califican --------

    public function test_ambas_partes_califican_revela_ambas_de_inmediato(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->service()->enableRatings($job);

        // Solo el cliente califica: todavía oculto (doble ciego).
        $this->submit($client, $job, 5, 'Excelente trabajo, muy profesional.');
        $this->assertCount(
            0,
            Rating::where('job_id', $job->id)->where('is_visible', true)->get(),
            'Con una sola parte calificando, nada debe ser visible.'
        );

        // El prestador también califica: ahora ambas se revelan.
        $this->submit($provider, $job, 4, 'Buen cliente, pagos puntuales.');

        $visibles = Rating::where('job_id', $job->id)->where('is_visible', true)->get();
        $this->assertCount(2, $visibles, 'Con ambas partes calificando, las dos deben ser visibles.');

        foreach ($visibles as $rating) {
            $this->assertNotNull($rating->rated_at);
            $this->assertTrue($rating->is_visible);
        }
    }

    public function test_mientras_falte_una_parte_nada_es_visible(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->service()->enableRatings($job);

        $this->submit($client, $job, 3, 'Comentario suficientemente largo.');

        $clientRow = Rating::where('job_id', $job->id)->where('rater_user_id', $client->id)->first();
        $providerRow = Rating::where('job_id', $job->id)->where('rater_user_id', $provider->id)->first();

        $this->assertFalse($clientRow->is_visible);
        $this->assertFalse($providerRow->is_visible);
    }

    // --- Requisito 8.5: revelación por tiempo (14 días) --------------------

    public function test_reveal_job_a_los_14_dias_revela_calificacion_emitida_aunque_solo_una_parte(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));

        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->service()->enableRatings($job);

        // Solo el cliente califica.
        $this->submit($client, $job, 5, 'Comentario suficientemente largo.');

        // Antes de los 14 días el Job es no-op (guarda isFuture).
        Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00'));
        (new RevealRatingsJob($job->id))->handle();
        $this->assertFalse(
            Rating::where('job_id', $job->id)->where('rater_user_id', $client->id)->first()->is_visible,
            'Antes de los 14 días no debe revelarse por tiempo.'
        );

        // Pasados los 14 días el Job revela la Calificación emitida.
        Carbon::setTestNow(Carbon::parse('2026-03-16 10:00:00'));
        (new RevealRatingsJob($job->id))->handle();

        $clientRow = Rating::where('job_id', $job->id)->where('rater_user_id', $client->id)->first();
        $providerRow = Rating::where('job_id', $job->id)->where('rater_user_id', $provider->id)->first();

        $this->assertTrue($clientRow->is_visible, 'La Calificación emitida se revela a los 14 días.');
        // La fila placeholder del prestador (sin emitir) sigue oculta.
        $this->assertFalse($providerRow->is_visible);

        Carbon::setTestNow();
    }

    public function test_reveal_job_es_idempotente_cuando_ya_se_revelo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));

        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->service()->enableRatings($job);

        // Ambas partes califican antes de los 14 días: revelación inmediata.
        $this->submit($client, $job, 5, 'Comentario suficientemente largo.');
        $this->submit($provider, $job, 4, 'Otro comentario suficientemente largo.');

        $visiblesAntes = Rating::where('job_id', $job->id)->where('is_visible', true)->count();
        $this->assertSame(2, $visiblesAntes);

        // El Job de 14 días corre más tarde y no cambia nada (no-op/cancelado).
        Carbon::setTestNow(Carbon::parse('2026-03-16 10:00:00'));
        (new RevealRatingsJob($job->id))->handle();

        $this->assertSame(
            2,
            Rating::where('job_id', $job->id)->where('is_visible', true)->count(),
            'El Job debe ser idempotente cuando ya se reveló.'
        );

        Carbon::setTestNow();
    }

    public function test_reveal_job_ignora_trabajo_inexistente(): void
    {
        (new RevealRatingsJob(999999))->handle();
        $this->assertTrue(true);
    }

    // --- Programación de Jobs al habilitar ---------------------------------

    public function test_enable_ratings_programa_reveal_y_close_jobs(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));

        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);

        $this->service()->enableRatings($job);

        $expectedReveal = now()->addDays(RatingService::REVEAL_DAYS);
        $expectedClose = now()->addDays(RatingService::WINDOW_DAYS);

        Queue::assertPushed(RevealRatingsJob::class, function (RevealRatingsJob $j) use ($job, $expectedReveal): bool {
            return $j->jobId === $job->id
                && $j->delay !== null
                && $expectedReveal->equalTo($j->delay);
        });

        Queue::assertPushed(CloseRatingWindowJob::class, function (CloseRatingWindowJob $j) use ($job, $expectedClose): bool {
            return $j->jobId === $job->id
                && $j->delay !== null
                && $expectedClose->equalTo($j->delay);
        });

        Carbon::setTestNow();
    }

    public function test_enable_ratings_idempotente_no_reprograma_jobs(): void
    {
        Queue::fake();

        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);

        $this->service()->enableRatings($job);
        $this->service()->enableRatings($job); // segunda finalización

        Queue::assertPushed(RevealRatingsJob::class, 1);
        Queue::assertPushed(CloseRatingWindowJob::class, 1);
    }

    // --- CloseRatingWindowJob: no-op seguro --------------------------------

    public function test_close_window_job_no_modifica_calificaciones(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));

        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->service()->enableRatings($job);

        $this->submit($client, $job, 5, 'Comentario suficientemente largo.');

        Carbon::setTestNow(Carbon::parse('2026-04-02 10:00:00')); // > 30 días
        (new CloseRatingWindowJob($job->id))->handle();

        // No altera visibilidad ni datos de las Calificaciones.
        $clientRow = Rating::where('job_id', $job->id)->where('rater_user_id', $client->id)->first();
        $this->assertFalse($clientRow->is_visible);
        $this->assertSame(5, $clientRow->score);
        $this->assertSame(2, $job->ratings()->count());

        Carbon::setTestNow();
    }

    public function test_close_window_job_ignora_trabajo_inexistente(): void
    {
        (new CloseRatingWindowJob(999999))->handle();
        $this->assertTrue(true);
    }
}

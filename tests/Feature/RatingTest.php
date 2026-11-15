<?php

namespace Tests\Feature;

use App\Contracts\JobCompletionHandler;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\Rating;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\RatingService;
use App\Support\RatingsJobCompletionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 12.1: habilitación y emisión de Calificaciones
 * (Requisitos 8.1, 8.2, 8.3, 8.4, 8.6, 8.7).
 */
class RatingTest extends TestCase
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

    private function makeJob(User $client, User $provider, string $status = Job::STATUS_COMPLETADO): Job
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
            'status' => $status,
            'start_date' => Carbon::today(),
            'estimated_delivery_date' => Carbon::today()->addDays(10),
            'actual_completion_date' => Carbon::today(),
        ]);
    }

    private function service(): RatingService
    {
        return app(RatingService::class);
    }

    /** Habilita las calificaciones de un Trabajo (como ocurre al completarlo). */
    private function enable(Job $job): void
    {
        $this->service()->enableRatings($job);
    }

    // --- Requisito 8.1: habilitación al completar --------------------------

    public function test_enable_ratings_crea_la_ventana_de_30_dias_para_ambas_partes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00'));

        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);

        $this->service()->enableRatings($job);

        $this->assertSame(2, $job->ratings()->count());

        $clientRow = Rating::where('job_id', $job->id)
            ->where('rater_user_id', $client->id)->first();
        $providerRow = Rating::where('job_id', $job->id)
            ->where('rater_user_id', $provider->id)->first();

        $this->assertNotNull($clientRow);
        $this->assertNotNull($providerRow);
        $this->assertSame($provider->id, $clientRow->rated_user_id);
        $this->assertSame($client->id, $providerRow->rated_user_id);

        foreach ([$clientRow, $providerRow] as $row) {
            $this->assertNull($row->rated_at);
            $this->assertFalse($row->is_visible);
            $this->assertTrue($row->enabled_at->equalTo(now()));
            $this->assertTrue($row->window_closes_at->equalTo(now()->addDays(30)));
        }

        Carbon::setTestNow();
    }

    public function test_enable_ratings_es_idempotente(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());

        $this->service()->enableRatings($job);
        $this->service()->enableRatings($job);

        $this->assertSame(2, $job->ratings()->count());
    }

    public function test_handler_habilita_calificaciones_al_completar(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());

        $handler = new RatingsJobCompletionHandler(fn (): RatingService => $this->service());
        $handler->onCompleted($job);

        $this->assertSame(2, $job->ratings()->count());
    }

    public function test_binding_por_defecto_del_seam_habilita_calificaciones(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());

        app(JobCompletionHandler::class)->onCompleted($job);

        // El composite debe ejecutar también el handler de Calificaciones.
        $this->assertSame(2, $job->ratings()->count());
    }

    // --- Requisitos 8.2, 8.4: emisión válida asociada correctamente --------

    public function test_calificacion_valida_se_persiste_asociada_al_trabajo_y_al_par_correcto(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->enable($job);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Excelente trabajo, muy profesional.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.rating.job_id', $job->id)
            ->assertJsonPath('data.rating.rater_user_id', $client->id)
            ->assertJsonPath('data.rating.rated_user_id', $provider->id)
            ->assertJsonPath('data.rating.score', 5)
            ->assertJsonPath('data.rating.is_visible', false);

        $this->assertDatabaseHas('ratings', [
            'job_id' => $job->id,
            'rater_user_id' => $client->id,
            'rated_user_id' => $provider->id,
            'score' => 5,
            'comment' => 'Excelente trabajo, muy profesional.',
            'is_visible' => false,
        ]);
    }

    public function test_cada_parte_califica_a_su_contraparte(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->enable($job);

        Sanctum::actingAs($provider);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 4,
            'comment' => 'Buen cliente, pagos puntuales.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.rating.rater_user_id', $provider->id)
            ->assertJsonPath('data.rating.rated_user_id', $client->id);
    }

    public function test_calificar_sin_autenticacion_retorna_401(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());
        $this->enable($job);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Comentario suficiente.',
        ])->assertStatus(401);
    }

    // --- Requisito 8.3: validación (422 sin persistir) ---------------------

    public function test_score_fuera_de_rango_retorna_422_sin_persistir(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());
        $this->enable($job);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 6,
            'comment' => 'Comentario suficientemente largo.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('score');

        $this->assertDatabaseMissing('ratings', [
            'job_id' => $job->id,
            'rater_user_id' => $client->id,
            'score' => 6,
        ]);
        // La fila placeholder no quedó marcada como emitida.
        $this->assertNull(
            Rating::where('job_id', $job->id)->where('rater_user_id', $client->id)->first()->rated_at
        );
    }

    public function test_score_no_entero_retorna_422(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());
        $this->enable($job);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 3.5,
            'comment' => 'Comentario suficientemente largo.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('score');
    }

    public function test_comentario_demasiado_corto_retorna_422(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());
        $this->enable($job);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'corto',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');
    }

    public function test_comentario_demasiado_largo_retorna_422(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());
        $this->enable($job);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => str_repeat('a', 501),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');
    }

    // --- Requisito 8.1: no habilitada / no participante --------------------

    public function test_calificar_trabajo_sin_habilitar_retorna_409(): void
    {
        $client = $this->client();
        // Trabajo en progreso, sin habilitar calificaciones.
        $job = $this->makeJob($client, $this->provider(), status: Job::STATUS_EN_PROGRESO);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Comentario suficientemente largo.',
        ])->assertStatus(409);
    }

    public function test_tercero_ajeno_al_trabajo_no_puede_calificar_403(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());
        $this->enable($job);

        $outsider = $this->client();
        Sanctum::actingAs($outsider);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Comentario suficientemente largo.',
        ])->assertStatus(403);
    }

    // --- Requisito 8.6: duplicada ------------------------------------------

    public function test_calificacion_duplicada_retorna_409(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());
        $this->enable($job);

        Sanctum::actingAs($client);

        $payload = [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Primera calificación válida.',
        ];

        $this->postJson('/api/v1/ratings', $payload)->assertCreated();

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 3,
            'comment' => 'Intento de segunda calificación.',
        ])->assertStatus(409);

        // La calificación original no se modificó (Requisito 8.6).
        $this->assertDatabaseHas('ratings', [
            'job_id' => $job->id,
            'rater_user_id' => $client->id,
            'score' => 5,
            'comment' => 'Primera calificación válida.',
        ]);
    }

    // --- Requisito 8.7: ventana cerrada (30 días) --------------------------

    public function test_calificar_fuera_de_la_ventana_de_30_dias_retorna_409(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));

        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());
        $this->enable($job);

        // Más de 30 días después.
        Carbon::setTestNow(Carbon::parse('2026-04-02 10:00:00'));

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Comentario suficientemente largo.',
        ])->assertStatus(409);

        Carbon::setTestNow();
    }

    public function test_calificar_justo_dentro_de_la_ventana_se_acepta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));

        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());
        $this->enable($job);

        // Dentro de los 30 días.
        Carbon::setTestNow(Carbon::parse('2026-03-29 10:00:00'));

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Comentario suficientemente largo.',
        ])->assertCreated();

        Carbon::setTestNow();
    }

    // --- GET /ratings/job/{job} --------------------------------------------

    public function test_listado_de_calificaciones_del_trabajo_para_las_partes(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->enable($job);

        Sanctum::actingAs($client);
        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Trabajo impecable, recomendado.',
        ])->assertCreated();

        Sanctum::actingAs($provider);
        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 4,
            'comment' => 'Cliente claro con los requisitos.',
        ])->assertCreated();

        Sanctum::actingAs($client);
        $this->getJson("/api/v1/ratings/job/{$job->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.ratings');
    }

    public function test_listado_solo_incluye_calificaciones_emitidas(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);
        $this->enable($job);

        // Solo el cliente califica; la fila placeholder del prestador no debe
        // aparecer en el listado.
        Sanctum::actingAs($client);
        $this->postJson('/api/v1/ratings', [
            'job_id' => $job->id,
            'score' => 5,
            'comment' => 'Trabajo impecable, recomendado.',
        ])->assertCreated();

        $this->getJson("/api/v1/ratings/job/{$job->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.ratings')
            ->assertJsonPath('data.ratings.0.rater_user_id', $client->id);
    }

    public function test_tercero_no_puede_ver_calificaciones_del_trabajo_403(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());
        $this->enable($job);

        Sanctum::actingAs($this->client()); // ajeno al trabajo

        $this->getJson("/api/v1/ratings/job/{$job->id}")
            ->assertStatus(403);
    }

    public function test_listado_sin_autenticacion_retorna_401(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());
        $this->enable($job);

        $this->getJson("/api/v1/ratings/job/{$job->id}")
            ->assertStatus(401);
    }
}

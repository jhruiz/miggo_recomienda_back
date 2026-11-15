<?php

namespace Tests\Feature;

use App\Contracts\JobCompletionHandler;
use App\Contracts\JobNotifier;
use App\Jobs\AutoCompleteJobJob;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature/integration tests para la tarea 9.2: completar, confirmar y rechazar
 * la finalización de un Trabajo, más el auto-completado (Requisitos 7.3, 7.4,
 * 7.5, 7.6).
 */
class JobCompletionTest extends TestCase
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

    private function makeJob(User $client, User $provider, string $status = Job::STATUS_EN_PROGRESO, ?string $completionDate = null): Job
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
            'actual_completion_date' => $completionDate,
        ]);
    }

    private function service(): JobService
    {
        return app(JobService::class);
    }

    // --- Requisito 7.3: el Prestador marca completado ----------------------

    public function test_complete_registra_fecha_notifica_y_despacha_auto_complete_con_delay_7_dias(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00'));

        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/jobs/{$job->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_EN_PROGRESO)
            ->assertJsonPath('data.job.actual_completion_date', '2026-03-10');

        $expectedDelay = now()->addDays(7);

        Queue::assertPushed(AutoCompleteJobJob::class, function (AutoCompleteJobJob $queued) use ($job, $expectedDelay): bool {
            return $queued->jobId === $job->id
                && $queued->delay !== null
                && $expectedDelay->equalTo($queued->delay);
        });

        Carbon::setTestNow();
    }

    public function test_complete_rechaza_a_quien_no_es_el_prestador_con_403(): void
    {
        Queue::fake();

        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());

        Sanctum::actingAs($client); // es el Cliente, no el Prestador

        $this->postJson("/api/v1/jobs/{$job->id}/complete")
            ->assertStatus(403);

        Queue::assertNotPushed(AutoCompleteJobJob::class);
    }

    public function test_complete_sobre_trabajo_ya_marcado_retorna_409(): void
    {
        Queue::fake();

        $provider = $this->provider();
        $job = $this->makeJob($this->client(), $provider, completionDate: '2026-03-01');

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/jobs/{$job->id}/complete")
            ->assertStatus(409);
    }

    public function test_complete_sin_autenticacion_retorna_401(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());

        $this->postJson("/api/v1/jobs/{$job->id}/complete")
            ->assertStatus(401);
    }

    // --- Requisito 7.4: el Cliente confirma --------------------------------

    public function test_confirm_cambia_estado_a_completado_e_invoca_el_handler(): void
    {
        $handler = Mockery::mock(JobCompletionHandler::class);
        $handler->shouldReceive('onCompleted')
            ->once()
            ->with(Mockery::on(fn (Job $j): bool => $j->status === Job::STATUS_COMPLETADO));
        $this->app->instance(JobCompletionHandler::class, $handler);

        $client = $this->client();
        $job = $this->makeJob($client, $this->provider(), completionDate: '2026-03-01');

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_COMPLETADO);

        $this->assertSame(Job::STATUS_COMPLETADO, $job->fresh()->status);
    }

    public function test_confirm_rechaza_a_quien_no_es_el_cliente_con_403(): void
    {
        $provider = $this->provider();
        $job = $this->makeJob($this->client(), $provider, completionDate: '2026-03-01');

        Sanctum::actingAs($provider); // es el Prestador, no el Cliente

        $this->postJson("/api/v1/jobs/{$job->id}/confirm")
            ->assertStatus(403);
    }

    public function test_confirm_sin_finalizacion_marcada_retorna_409(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider()); // sin actual_completion_date

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/confirm")
            ->assertStatus(409);
    }

    // --- Requisito 7.6: el Cliente rechaza ---------------------------------

    public function test_reject_completion_mantiene_en_progreso_y_guarda_motivo(): void
    {
        $client = $this->client();
        $provider = $this->provider();

        $notifier = Mockery::mock(JobNotifier::class);
        $notifier->shouldReceive('notifyProviderOfRejection')
            ->once()
            ->with(Mockery::type(Job::class), 'No se instaló el tomacorriente de la sala.');
        $this->app->instance(JobNotifier::class, $notifier);

        $job = $this->makeJob($client, $provider, completionDate: '2026-03-01');

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/reject-completion", [
            'reason' => 'No se instaló el tomacorriente de la sala.',
        ])
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_EN_PROGRESO)
            ->assertJsonPath('data.job.rejection_reason', 'No se instaló el tomacorriente de la sala.')
            ->assertJsonPath('data.job.actual_completion_date', null);

        $fresh = $job->fresh();
        $this->assertSame(Job::STATUS_EN_PROGRESO, $fresh->status);
        $this->assertSame('No se instaló el tomacorriente de la sala.', $fresh->rejection_reason);
        $this->assertNull($fresh->actual_completion_date);
    }

    public function test_reject_completion_motivo_demasiado_largo_retorna_422(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider(), completionDate: '2026-03-01');

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/reject-completion", [
            'reason' => str_repeat('a', 501),
        ])
            ->assertStatus(422)
            ->assertJsonPath('data', null);

        // No se modificó el Trabajo.
        $this->assertSame('2026-03-01', $job->fresh()->actual_completion_date->toDateString());
    }

    public function test_reject_completion_sin_motivo_retorna_422(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider(), completionDate: '2026-03-01');

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/reject-completion", [])
            ->assertStatus(422);
    }

    public function test_reject_completion_rechaza_a_quien_no_es_el_cliente_con_403(): void
    {
        $provider = $this->provider();
        $job = $this->makeJob($this->client(), $provider, completionDate: '2026-03-01');

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/jobs/{$job->id}/reject-completion", [
            'reason' => 'Motivo cualquiera',
        ])
            ->assertStatus(403);
    }

    // --- Requisito 7.5: AutoCompleteJobJob::handle() -----------------------

    public function test_auto_complete_handle_completa_trabajo_pendiente_de_confirmacion(): void
    {
        $job = $this->makeJob($this->client(), $this->provider(), completionDate: '2026-03-01');

        $handler = Mockery::mock(JobCompletionHandler::class);
        $handler->shouldReceive('onCompleted')
            ->once()
            ->with(Mockery::on(fn (Job $j): bool => $j->id === $job->id
                && $j->status === Job::STATUS_COMPLETADO));

        (new AutoCompleteJobJob($job->id))->handle($handler);

        $this->assertSame(Job::STATUS_COMPLETADO, $job->fresh()->status);
    }

    public function test_auto_complete_handle_no_toca_trabajo_ya_completado(): void
    {
        $job = $this->makeJob($this->client(), $this->provider(), status: Job::STATUS_COMPLETADO, completionDate: '2026-03-01');

        $handler = Mockery::mock(JobCompletionHandler::class);
        $handler->shouldNotReceive('onCompleted');

        (new AutoCompleteJobJob($job->id))->handle($handler);

        $this->assertSame(Job::STATUS_COMPLETADO, $job->fresh()->status);
    }

    public function test_auto_complete_handle_no_completa_trabajo_tras_rechazo(): void
    {
        // Tras un rechazo el Trabajo vuelve a "en_progreso" sin fecha de
        // finalización: el AutoCompleteJobJob pendiente debe ser no-op (Req 7.6).
        $job = $this->makeJob($this->client(), $this->provider(), status: Job::STATUS_EN_PROGRESO, completionDate: null);

        $handler = Mockery::mock(JobCompletionHandler::class);
        $handler->shouldNotReceive('onCompleted');

        (new AutoCompleteJobJob($job->id))->handle($handler);

        $this->assertSame(Job::STATUS_EN_PROGRESO, $job->fresh()->status);
    }

    public function test_auto_complete_handle_ignora_trabajo_inexistente(): void
    {
        $handler = Mockery::mock(JobCompletionHandler::class);
        $handler->shouldNotReceive('onCompleted');

        (new AutoCompleteJobJob(999999))->handle($handler);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

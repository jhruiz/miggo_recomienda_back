<?php

namespace Tests\Feature;

use App\Contracts\JobNotifier;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature/integration tests para la tarea 9.4: cancelación de un Trabajo por
 * mutuo acuerdo (Requisitos 7.7, 7.8).
 *
 *  - request-cancel de una sola parte: mantiene "en_progreso" y notifica a la
 *    contraparte (Requisito 7.8).
 *  - confirm-cancel de la otra parte (ambos flags true): pasa a "cancelado" y
 *    conserva ambos motivos (Requisito 7.7).
 *  - Autorización (403 para terceros), estado inválido (409 fuera de
 *    "en_progreso") y validación del motivo (422 si >500 o ausente).
 */
class JobCancellationTest extends TestCase
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

    private function makeJob(
        User $client,
        User $provider,
        string $status = Job::STATUS_EN_PROGRESO,
        bool $clientRequested = false,
        bool $providerRequested = false,
        ?array $cancellationReason = null,
    ): Job {
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
            'client_requested_cancel' => $clientRequested,
            'provider_requested_cancel' => $providerRequested,
            'cancellation_reason' => $cancellationReason,
        ]);
    }

    // --- Requisito 7.8: una sola parte solicita ----------------------------

    public function test_request_cancel_una_parte_mantiene_en_progreso_y_notifica_a_la_otra(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);

        $notifier = Mockery::mock(JobNotifier::class);
        $notifier->shouldReceive('notifyCancellationRequested')
            ->once()
            ->with(Mockery::type(Job::class), $provider->id, 'Ya no necesito el servicio.');
        $notifier->shouldNotReceive('notifyJobCancelled');
        $this->app->instance(JobNotifier::class, $notifier);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [
            'reason' => 'Ya no necesito el servicio.',
        ])
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_EN_PROGRESO)
            ->assertJsonPath('data.job.client_requested_cancel', true)
            ->assertJsonPath('data.job.provider_requested_cancel', false)
            ->assertJsonPath('data.job.cancellation_reason.client', 'Ya no necesito el servicio.');

        $fresh = $job->fresh();
        $this->assertSame(Job::STATUS_EN_PROGRESO, $fresh->status);
        $this->assertTrue($fresh->client_requested_cancel);
        $this->assertFalse($fresh->provider_requested_cancel);
        $this->assertSame('Ya no necesito el servicio.', $fresh->cancellation_reason['client']);
    }

    public function test_request_cancel_del_prestador_notifica_al_cliente(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);

        $notifier = Mockery::mock(JobNotifier::class);
        $notifier->shouldReceive('notifyCancellationRequested')
            ->once()
            ->with(Mockery::type(Job::class), $client->id, 'No puedo continuar con el trabajo.');
        $this->app->instance(JobNotifier::class, $notifier);

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [
            'reason' => 'No puedo continuar con el trabajo.',
        ])
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_EN_PROGRESO)
            ->assertJsonPath('data.job.provider_requested_cancel', true)
            ->assertJsonPath('data.job.cancellation_reason.provider', 'No puedo continuar con el trabajo.');
    }

    // --- Requisito 7.7: ambas partes → cancelado ---------------------------

    public function test_confirm_cancel_con_ambas_partes_cancela_y_guarda_ambos_motivos(): void
    {
        $client = $this->client();
        $provider = $this->provider();

        // El Cliente ya solicitó la cancelación previamente.
        $job = $this->makeJob(
            $client,
            $provider,
            clientRequested: true,
            cancellationReason: ['client' => 'Ya no necesito el servicio.'],
        );

        $notifier = Mockery::mock(JobNotifier::class);
        $notifier->shouldReceive('notifyJobCancelled')
            ->once()
            ->with(Mockery::on(fn (Job $j): bool => $j->status === Job::STATUS_CANCELADO), $client->id);
        $notifier->shouldNotReceive('notifyCancellationRequested');
        $this->app->instance(JobNotifier::class, $notifier);

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/jobs/{$job->id}/confirm-cancel", [
            'reason' => 'De acuerdo, cancelamos.',
        ])
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_CANCELADO)
            ->assertJsonPath('data.job.client_requested_cancel', true)
            ->assertJsonPath('data.job.provider_requested_cancel', true)
            ->assertJsonPath('data.job.cancellation_reason.client', 'Ya no necesito el servicio.')
            ->assertJsonPath('data.job.cancellation_reason.provider', 'De acuerdo, cancelamos.');

        $fresh = $job->fresh();
        $this->assertSame(Job::STATUS_CANCELADO, $fresh->status);
        $this->assertSame('Ya no necesito el servicio.', $fresh->cancellation_reason['client']);
        $this->assertSame('De acuerdo, cancelamos.', $fresh->cancellation_reason['provider']);
    }

    public function test_request_cancel_de_la_segunda_parte_tambien_cancela(): void
    {
        // request-cancel invocado por la segunda parte también cierra el acuerdo.
        $client = $this->client();
        $provider = $this->provider();

        $job = $this->makeJob(
            $client,
            $provider,
            providerRequested: true,
            cancellationReason: ['provider' => 'No puedo continuar.'],
        );

        $notifier = Mockery::mock(JobNotifier::class);
        $notifier->shouldReceive('notifyJobCancelled')->once();
        $this->app->instance(JobNotifier::class, $notifier);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [
            'reason' => 'Está bien, lo cancelamos.',
        ])
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_CANCELADO);

        $this->assertSame(Job::STATUS_CANCELADO, $job->fresh()->status);
    }

    // --- Autorización (403) ------------------------------------------------

    public function test_request_cancel_de_un_tercero_retorna_403(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $job = $this->makeJob($client, $provider);

        $outsider = $this->client();

        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [
            'reason' => 'Motivo cualquiera',
        ])
            ->assertStatus(403);

        $this->assertSame(Job::STATUS_EN_PROGRESO, $job->fresh()->status);
    }

    public function test_confirm_cancel_de_un_tercero_retorna_403(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());
        $outsider = $this->client();

        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/jobs/{$job->id}/confirm-cancel", [
            'reason' => 'Motivo cualquiera',
        ])
            ->assertStatus(403);
    }

    // --- Estado inválido (409) ---------------------------------------------

    public function test_request_cancel_sobre_trabajo_no_en_progreso_retorna_409(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider(), status: Job::STATUS_COMPLETADO);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [
            'reason' => 'Quiero cancelar',
        ])
            ->assertStatus(409);
    }

    public function test_confirm_cancel_sobre_trabajo_ya_cancelado_retorna_409(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider(), status: Job::STATUS_CANCELADO);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/confirm-cancel", [
            'reason' => 'Quiero cancelar',
        ])
            ->assertStatus(409);
    }

    // --- Validación del motivo (422) ---------------------------------------

    public function test_request_cancel_motivo_demasiado_largo_retorna_422(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [
            'reason' => str_repeat('a', 501),
        ])
            ->assertStatus(422)
            ->assertJsonPath('data', null);

        // No se modificó el Trabajo.
        $fresh = $job->fresh();
        $this->assertFalse($fresh->client_requested_cancel);
        $this->assertSame(Job::STATUS_EN_PROGRESO, $fresh->status);
    }

    public function test_request_cancel_sin_motivo_retorna_422(): void
    {
        $client = $this->client();
        $job = $this->makeJob($client, $this->provider());

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [])
            ->assertStatus(422);
    }

    public function test_request_cancel_sin_autenticacion_retorna_401(): void
    {
        $job = $this->makeJob($this->client(), $this->provider());

        $this->postJson("/api/v1/jobs/{$job->id}/request-cancel", [
            'reason' => 'Motivo',
        ])
            ->assertStatus(401);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

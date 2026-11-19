<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 9.1: creación de Trabajo con cálculo de fechas
 * (JobService::createJob) y endpoints GET /jobs y GET /jobs/{id}
 * (Requisitos 7.1, 7.2).
 */
class JobTest extends TestCase
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

    private function makeQuote(User $client, User $provider, int $deliveryDays = 10): Quote
    {
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Instalación eléctrica',
            'description' => 'Instalar tomas en la cocina',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        return Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio completo',
            'total_price' => 302.00,
            'delivery_days' => $deliveryDays,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);
    }

    private function service(): JobService
    {
        return app(JobService::class);
    }

    // --- Requisito 7.1: creación de Trabajo y cálculo de fechas ------------

    public function test_create_job_registra_fechas_y_estado_en_progreso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 15:30:00'));

        $client = $this->client();
        $provider = $this->provider();
        $quote = $this->makeQuote($client, $provider, deliveryDays: 10);

        $job = $this->service()->createJob($quote);

        $this->assertSame(Job::STATUS_EN_PROGRESO, $job->status);
        $this->assertSame($client->id, $job->client_user_id);
        $this->assertSame($provider->id, $job->provider_user_id);
        $this->assertSame($quote->id, $job->quote_id);
        // start_date = fecha de aceptación (hoy, a nivel de día).
        $this->assertSame('2026-01-10', $job->start_date->toDateString());
        // estimated_delivery_date = start_date + 10 días calendario.
        $this->assertSame('2026-01-20', $job->estimated_delivery_date->toDateString());

        $this->assertDatabaseHas('service_jobs', [
            'quote_id' => $quote->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => Job::STATUS_EN_PROGRESO,
            'start_date' => '2026-01-10 00:00:00',
            'estimated_delivery_date' => '2026-01-20 00:00:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_delivery_date_cruza_fin_de_mes_como_dias_calendario(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-25 09:00:00'));

        $job = $this->service()->createJob(
            $this->makeQuote($this->client(), $this->provider(), deliveryDays: 10),
        );

        // 2026-01-25 + 10 días = 2026-02-04 (días calendario, cruza febrero).
        $this->assertSame('2026-02-04', $job->estimated_delivery_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_create_job_es_idempotente_por_cotizacion(): void
    {
        $quote = $this->makeQuote($this->client(), $this->provider());

        $first = $this->service()->createJob($quote);
        $second = $this->service()->createJob($quote->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('service_jobs', 1);
    }

    // --- Requisito 18: categoría del Trabajo --------------------------------

    public function test_create_job_copia_la_categoria_de_la_solicitud_en_cotizacion_publica(): void
    {
        $quote = $this->makeQuote($this->client(), $this->provider());

        $this->assertNull($quote->service_category_id);

        $job = $this->service()->createJob($quote);

        $this->assertSame($this->category()->id, $job->service_category_id);
        $this->assertDatabaseHas('service_jobs', [
            'id' => $job->id,
            'service_category_id' => $this->category()->id,
        ]);
    }

    public function test_create_job_copia_la_categoria_de_la_cotizacion_directa(): void
    {
        $category = $this->category('electricidad');

        $quote = Quote::create([
            'provider_user_id' => $this->provider()->id,
            'client_user_id' => $this->client()->id,
            'quote_request_id' => null,
            'service_category_id' => $category->id,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => 'Servicio directo',
            'total_price' => 100.00,
            'delivery_days' => 5,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);

        $job = $this->service()->createJob($quote);

        $this->assertSame($category->id, $job->service_category_id);
        $this->assertDatabaseHas('service_jobs', [
            'id' => $job->id,
            'service_category_id' => $category->id,
        ]);
    }

    public function test_create_job_idempotente_conserva_la_categoria(): void
    {
        $quote = $this->makeQuote($this->client(), $this->provider());

        $first = $this->service()->createJob($quote);
        $second = $this->service()->createJob($quote->refresh());

        $this->assertSame($first->service_category_id, $second->service_category_id);
        $this->assertSame($this->category()->id, $second->service_category_id);
    }

    // --- GET /jobs ---------------------------------------------------------

    public function test_listado_incluye_trabajos_como_cliente_y_como_prestador(): void
    {
        $user = $this->provider();
        $other = $this->provider();
        $otherClient = $this->client();

        // Trabajo donde $user es Prestador.
        $asProvider = $this->service()->createJob($this->makeQuote($otherClient, $user));
        // Trabajo donde $user es Cliente.
        $asClient = $this->service()->createJob($this->makeQuote($user, $other));
        // Trabajo ajeno (no participa).
        $this->service()->createJob($this->makeQuote($otherClient, $other));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonCount(2, 'data.jobs');

        $ids = collect($response->json('data.jobs'))->pluck('id')->all();
        $this->assertContains($asProvider->id, $ids);
        $this->assertContains($asClient->id, $ids);
    }

    public function test_listado_vacio_responde_200(): void
    {
        Sanctum::actingAs($this->client());

        $this->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data.jobs');
    }

    public function test_listado_sin_autenticacion_retorna_401(): void
    {
        $this->getJson('/api/v1/jobs')->assertStatus(401);
    }

    // --- GET /jobs/{id} ----------------------------------------------------

    public function test_cliente_ve_detalle_de_su_trabajo(): void
    {
        $client = $this->client();
        $job = $this->service()->createJob($this->makeQuote($client, $this->provider()));

        Sanctum::actingAs($client);

        $this->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.job.id', $job->id)
            ->assertJsonPath('data.job.status', Job::STATUS_EN_PROGRESO);
    }

    public function test_prestador_ve_detalle_de_su_trabajo(): void
    {
        $provider = $this->provider();
        $job = $this->service()->createJob($this->makeQuote($this->client(), $provider));

        Sanctum::actingAs($provider);

        $this->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.job.id', $job->id);
    }

    public function test_tercero_no_puede_ver_el_trabajo(): void
    {
        $job = $this->service()->createJob($this->makeQuote($this->client(), $this->provider()));

        Sanctum::actingAs($this->client()); // usuario ajeno al trabajo

        $this->getJson("/api/v1/jobs/{$job->id}")
            ->assertStatus(403);
    }

    public function test_detalle_sin_autenticacion_retorna_401(): void
    {
        $job = $this->service()->createJob($this->makeQuote($this->client(), $this->provider()));

        $this->getJson("/api/v1/jobs/{$job->id}")
            ->assertStatus(401);
    }

    public function test_detalle_de_trabajo_inexistente_retorna_404(): void
    {
        Sanctum::actingAs($this->client());

        $this->getJson('/api/v1/jobs/999999')
            ->assertStatus(404);
    }
}

<?php

namespace Tests\Feature;

use App\Contracts\JobCompletionHandler;
use App\Models\Job;
use App\Models\ProviderProfile;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobService;
use App\Support\ComplianceJobCompletionHandler;
use App\Support\CompositeJobCompletionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests para la tarea 9.6: cálculo y cacheo del Índice_Cumplimiento
 * (Requisitos 9.1, 9.3, 9.5, 9.6).
 *
 * Cubre:
 *  - El ratio entero sobre Trabajos completados CON fecha estimada.
 *  - La exclusión de Trabajos completados SIN fecha estimada del ratio,
 *    sin afectar el conteo de Trabajos completados (Requisito 9.6).
 *  - El caso "Sin datos suficientes" (índice null) cuando ningún Trabajo
 *    completado tiene fecha estimada (Requisito 9.5).
 *  - El cacheo en provider_profiles y su actualización al completar un
 *    Trabajo vía el seam de finalización (Requisito 9.3).
 *  - La integración con confirmCompletion y el auto-completado.
 */
class ComplianceIndexTest extends TestCase
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

    /**
     * Crea un Prestador (User con rol prestador) junto a su provider_profile.
     *
     * @return array{0: User, 1: ProviderProfile}
     */
    private function provider(): array
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');
        $user->assignRole('prestador');

        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return [$user, $profile];
    }

    /**
     * Crea un Trabajo con el estado y fechas indicados.
     *
     * @param  string|null  $estimated  fecha estimada (Y-m-d) o null para "sin estimada"
     * @param  string|null  $actual     fecha real de finalización (Y-m-d) o null
     */
    private function makeJob(
        User $client,
        User $provider,
        string $status = Job::STATUS_COMPLETADO,
        ?string $estimated = '2026-03-10',
        ?string $actual = '2026-03-08',
    ): Job {
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Trabajo',
            'description' => 'Descripción del trabajo a realizar',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio completo',
            'total_price' => 300.00,
            'delivery_days' => 10,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);

        return Job::create([
            'quote_id' => $quote->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => $status,
            'start_date' => Carbon::parse('2026-03-01'),
            'estimated_delivery_date' => $estimated,
            'actual_completion_date' => $actual,
        ]);
    }

    private function service(): JobService
    {
        return app(JobService::class);
    }

    // --- Requisito 9.1: cálculo del porcentaje entero ----------------------

    public function test_calcula_porcentaje_entero_sobre_trabajos_con_fecha_estimada(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        // 2 a tiempo, 2 tarde => 50%.
        $this->makeJob($client, $provider, estimated: '2026-03-10', actual: '2026-03-08'); // a tiempo
        $this->makeJob($client, $provider, estimated: '2026-03-10', actual: '2026-03-10'); // a tiempo (igual)
        $this->makeJob($client, $provider, estimated: '2026-03-10', actual: '2026-03-11'); // tarde
        $this->makeJob($client, $provider, estimated: '2026-03-10', actual: '2026-03-20'); // tarde

        $index = $this->service()->recalculateComplianceIndex($provider);

        $this->assertSame(50, $index);

        $profile->refresh();
        $this->assertSame(50, $profile->compliance_index);
        $this->assertSame(4, $profile->completed_jobs_count);
    }

    public function test_redondea_el_porcentaje_a_entero(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        // 2 de 3 a tiempo => 66.66% => redondea a 67.
        $this->makeJob($client, $provider, actual: '2026-03-08'); // a tiempo
        $this->makeJob($client, $provider, actual: '2026-03-09'); // a tiempo
        $this->makeJob($client, $provider, actual: '2026-03-15'); // tarde

        $index = $this->service()->recalculateComplianceIndex($provider);

        $this->assertSame(67, $index);
        $this->assertSame(67, $profile->refresh()->compliance_index);
    }

    public function test_cien_por_ciento_cuando_todos_a_tiempo(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        $this->makeJob($client, $provider, actual: '2026-03-01');
        $this->makeJob($client, $provider, actual: '2026-03-05');
        $this->makeJob($client, $provider, actual: '2026-03-10');

        $this->assertSame(100, $this->service()->recalculateComplianceIndex($provider));
        $this->assertSame(100, $profile->refresh()->compliance_index);
    }

    public function test_cero_por_ciento_cuando_todos_tarde(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        $this->makeJob($client, $provider, actual: '2026-03-11');
        $this->makeJob($client, $provider, actual: '2026-03-12');
        $this->makeJob($client, $provider, actual: '2026-03-13');

        $this->assertSame(0, $this->service()->recalculateComplianceIndex($provider));
        $this->assertSame(0, $profile->refresh()->compliance_index);
    }

    // --- Requisito 9.6: exclusión de Trabajos sin fecha estimada -----------

    public function test_excluye_trabajos_sin_fecha_estimada_del_ratio_sin_afectar_el_conteo(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        // 2 completados CON fecha estimada: 1 a tiempo, 1 tarde => 50%.
        $this->makeJob($client, $provider, estimated: '2026-03-10', actual: '2026-03-08'); // a tiempo
        $this->makeJob($client, $provider, estimated: '2026-03-10', actual: '2026-03-15'); // tarde

        // 2 completados SIN fecha estimada: se excluyen del ratio pero cuentan
        // en completed_jobs_count (Requisito 9.6).
        $this->makeJob($client, $provider, estimated: null, actual: '2026-03-20');
        $this->makeJob($client, $provider, estimated: null, actual: null);

        $index = $this->service()->recalculateComplianceIndex($provider);

        // El ratio ignora los dos sin fecha estimada => 1/2 = 50%.
        $this->assertSame(50, $index);

        $profile->refresh();
        $this->assertSame(50, $profile->compliance_index);
        // El conteo incluye los 4 completados (Requisito 9.6).
        $this->assertSame(4, $profile->completed_jobs_count);
    }

    // --- Requisito 9.5: "Sin datos suficientes" (índice null) --------------

    public function test_indice_null_cuando_ningun_completado_tiene_fecha_estimada(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        $this->makeJob($client, $provider, estimated: null, actual: '2026-03-08');
        $this->makeJob($client, $provider, estimated: null, actual: '2026-03-09');
        $this->makeJob($client, $provider, estimated: null, actual: null);

        $index = $this->service()->recalculateComplianceIndex($provider);

        $this->assertNull($index);

        $profile->refresh();
        $this->assertNull($profile->compliance_index);
        // Conteo intacto (Requisito 9.6).
        $this->assertSame(3, $profile->completed_jobs_count);
    }

    public function test_solo_cuenta_trabajos_completados_no_en_progreso_ni_cancelados(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        // Completados: cuentan.
        $this->makeJob($client, $provider, status: Job::STATUS_COMPLETADO, actual: '2026-03-08'); // a tiempo
        $this->makeJob($client, $provider, status: Job::STATUS_COMPLETADO, actual: '2026-03-15'); // tarde

        // No completados: NO cuentan.
        $this->makeJob($client, $provider, status: Job::STATUS_EN_PROGRESO, actual: null);
        $this->makeJob($client, $provider, status: Job::STATUS_CANCELADO, actual: '2026-03-08');

        $index = $this->service()->recalculateComplianceIndex($provider);

        $this->assertSame(50, $index);
        $this->assertSame(2, $profile->refresh()->completed_jobs_count);
    }

    public function test_no_cuenta_trabajos_de_otros_prestadores(): void
    {
        [$provider, $profile] = $this->provider();
        [$otherProvider] = $this->provider();
        $client = $this->client();

        $this->makeJob($client, $provider, actual: '2026-03-08'); // a tiempo (del provider)
        $this->makeJob($client, $otherProvider, actual: '2026-03-20'); // tarde (de otro)

        $this->assertSame(100, $this->service()->recalculateComplianceIndex($provider));
        $this->assertSame(1, $profile->refresh()->completed_jobs_count);
    }

    // --- Robustez de la firma / sin perfil ---------------------------------

    public function test_recalcula_aceptando_user_profile_o_id(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        $this->makeJob($client, $provider, actual: '2026-03-08');

        $this->assertSame(100, $this->service()->recalculateComplianceIndex($provider));
        $this->assertSame(100, $this->service()->recalculateComplianceIndex($provider->id));
        $this->assertSame(100, $this->service()->recalculateComplianceIndex($profile));
    }

    public function test_no_op_cuando_el_prestador_no_tiene_perfil(): void
    {
        $client = $this->client();
        $providerUser = User::factory()->create();
        $providerUser->assignRole('prestador'); // sin provider_profile

        $this->makeJob($client, $providerUser, actual: '2026-03-08');

        $this->assertNull($this->service()->recalculateComplianceIndex($providerUser));
    }

    public function test_recalculo_es_idempotente(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        $this->makeJob($client, $provider, actual: '2026-03-08'); // a tiempo
        $this->makeJob($client, $provider, actual: '2026-03-15'); // tarde

        $this->assertSame(50, $this->service()->recalculateComplianceIndex($provider));
        $this->assertSame(50, $this->service()->recalculateComplianceIndex($provider));
        $this->assertSame(50, $profile->refresh()->compliance_index);
        $this->assertSame(2, $profile->completed_jobs_count);
    }

    // --- Requisito 9.3: recálculo al completar vía el seam -----------------

    public function test_compliance_handler_recalcula_el_indice_al_completar(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        // Historial previo: 1 a tiempo, 1 tarde.
        $this->makeJob($client, $provider, actual: '2026-03-08'); // a tiempo
        $this->makeJob($client, $provider, actual: '2026-03-15'); // tarde

        // Un Trabajo recién completado (a tiempo) que dispara el recálculo.
        $justCompleted = $this->makeJob($client, $provider, actual: '2026-03-09');

        $handler = new ComplianceJobCompletionHandler(fn (): JobService => $this->service());
        $handler->onCompleted($justCompleted);

        $profile->refresh();
        // 2 de 3 a tiempo => 67%.
        $this->assertSame(67, $profile->compliance_index);
        $this->assertSame(3, $profile->completed_jobs_count);
    }

    public function test_composite_handler_delega_en_todos_los_handlers(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        $job = $this->makeJob($client, $provider, actual: '2026-03-08');

        $spy = new class implements JobCompletionHandler {
            public bool $called = false;

            public function onCompleted(Job $job): void
            {
                $this->called = true;
            }
        };

        $composite = new CompositeJobCompletionHandler(
            new ComplianceJobCompletionHandler(fn (): JobService => $this->service()),
            $spy,
        );

        $composite->onCompleted($job);

        $this->assertTrue($spy->called, 'El handler adicional (p. ej. tarea 12.1) debe ejecutarse.');
        $this->assertSame(100, $profile->refresh()->compliance_index);
    }

    public function test_binding_por_defecto_resuelve_a_composite_con_compliance(): void
    {
        $handler = app(JobCompletionHandler::class);

        $this->assertInstanceOf(CompositeJobCompletionHandler::class, $handler);
    }

    // --- Integración: confirmar finalización recalcula el índice -----------

    public function test_confirm_completion_recalcula_el_indice_cacheado(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        // Dos trabajos ya completados a tiempo.
        $this->makeJob($client, $provider, actual: '2026-03-08');
        $this->makeJob($client, $provider, actual: '2026-03-09');

        // Un tercer trabajo a la espera de confirmación (en_progreso con fecha
        // real ya marcada), que será tarde respecto a la estimada.
        $pending = $this->makeJob(
            $client,
            $provider,
            status: Job::STATUS_EN_PROGRESO,
            estimated: '2026-03-10',
            actual: '2026-03-20',
        );

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/jobs/{$pending->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.job.status', Job::STATUS_COMPLETADO);

        $profile->refresh();
        // 2 de 3 a tiempo => 67%.
        $this->assertSame(67, $profile->compliance_index);
        $this->assertSame(3, $profile->completed_jobs_count);
    }

    public function test_auto_complete_recalcula_el_indice_cacheado(): void
    {
        [$provider, $profile] = $this->provider();
        $client = $this->client();

        $this->makeJob($client, $provider, actual: '2026-03-08'); // a tiempo

        // Trabajo pendiente de confirmación, tarde: al auto-completarse debe
        // recalcularse el índice vía el seam real (Requisito 7.5 + 9.3).
        $pending = $this->makeJob(
            $client,
            $provider,
            status: Job::STATUS_EN_PROGRESO,
            estimated: '2026-03-10',
            actual: '2026-03-20',
        );

        (new \App\Jobs\AutoCompleteJobJob($pending->id))->handle(app(JobCompletionHandler::class));

        $this->assertSame(Job::STATUS_COMPLETADO, $pending->fresh()->status);

        $profile->refresh();
        // 1 de 2 a tiempo => 50%.
        $this->assertSame(50, $profile->compliance_index);
        $this->assertSame(2, $profile->completed_jobs_count);
    }
}

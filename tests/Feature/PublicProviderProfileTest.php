<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\ProviderProfile;
use App\Models\Quote;
use App\Models\Rating;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests para la tarea 5.4: perfil público del Prestador con cálculo
 * de calificación promedio.
 *
 * Cubre los Requisitos 3.1, 3.5, 3.6, 3.9, 9.2 y 9.4.
 */
class PublicProviderProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea un Prestador con su provider_profile activo.
     */
    private function makeProvider(array $profileAttributes = []): ProviderProfile
    {
        $user = User::factory()->create(['name' => 'Juana Prestadora']);

        return ProviderProfile::create(array_merge([
            'user_id' => $user->id,
            'professional_description' => 'Carpintera con 8 años de experiencia.',
            'location' => 'Santo Domingo',
            'is_active' => true,
        ], $profileAttributes));
    }

    /**
     * Crea un Trabajo mínimo (vía la cadena Quote → Job) para asociar una
     * calificación, ya que `ratings.job_id` es obligatorio.
     */
    private function makeJob(ProviderProfile $profile, User $client): Job
    {
        $quote = Quote::create([
            'provider_user_id' => $profile->user_id,
            'client_user_id' => $client->id,
            'quote_request_id' => null,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => 'Servicio de prueba.',
            'total_price' => 100.00,
            'delivery_days' => 5,
            'expires_at' => now()->addDays(10),
            'status' => Quote::STATUS_ACEPTADA,
        ]);

        return Job::create([
            'quote_id' => $quote->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $profile->user_id,
            'status' => Job::STATUS_COMPLETADO,
            'start_date' => now()->subDays(10),
            'estimated_delivery_date' => now()->subDays(5),
            'actual_completion_date' => now()->subDays(6),
        ]);
    }

    /**
     * Crea una calificación recibida por el usuario del prestador.
     */
    private function rate(ProviderProfile $profile, int $score, bool $visible = true, ?string $comment = null): Rating
    {
        $rater = User::factory()->create();
        $job = $this->makeJob($profile, $rater);

        return Rating::create([
            'job_id' => $job->id,
            'rater_user_id' => $rater->id,
            'rated_user_id' => $profile->user_id,
            'score' => $score,
            'comment' => $comment ?? "Comentario de prueba con score {$score}.",
            'is_visible' => $visible,
            'rated_at' => now(),
            'enabled_at' => now(),
            'window_closes_at' => now()->addDays(30),
        ]);
    }

    // --- Requisito 3.1: perfil público con todos los campos ---------------

    public function test_perfil_publico_retorna_los_campos_requeridos(): void
    {
        $profile = $this->makeProvider();
        $category = ServiceCategory::create(['name' => 'Carpintería', 'slug' => 'carpinteria']);
        $profile->categories()->attach($category->id);

        $response = $this->getJson("/api/v1/providers/{$profile->id}");

        $response->assertOk()
            ->assertJsonPath('data.provider.name', 'Juana Prestadora')
            ->assertJsonPath('data.provider.professional_description', 'Carpintera con 8 años de experiencia.')
            ->assertJsonPath('data.provider.categories.0.slug', 'carpinteria')
            ->assertJsonStructure([
                'data' => ['provider' => [
                    'id', 'user_id', 'name', 'professional_description', 'location',
                    'categories', 'portfolios', 'average_rating', 'compliance_index',
                    'completed_jobs_count', 'reviews',
                ]],
            ]);
    }

    // --- Requisito 3.9: "Sin calificaciones" cuando no hay ratings --------

    public function test_sin_calificaciones_muestra_etiqueta(): void
    {
        $profile = $this->makeProvider();

        $this->getJson("/api/v1/providers/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider.average_rating', 'Sin calificaciones');
    }

    // --- Requisito 3.6: promedio aritmético redondeado a 1 decimal --------

    public function test_promedio_es_media_aritmetica_redondeada_a_un_decimal(): void
    {
        $profile = $this->makeProvider();
        // Scores 5, 4, 4 => media 4.333... => 4.3
        $this->rate($profile, 5);
        $this->rate($profile, 4);
        $this->rate($profile, 4);

        $this->getJson("/api/v1/providers/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider.average_rating', 4.3);

        // El valor se cachea en provider_profiles.average_rating.
        $this->assertDatabaseHas('provider_profiles', [
            'id' => $profile->id,
            'average_rating' => 4.3,
        ]);
    }

    public function test_calculate_average_rating_redondea_a_la_mitad(): void
    {
        $profile = $this->makeProvider();
        // Scores 5 y 2 => media 3.5 (sin redondeo adicional).
        $this->rate($profile, 5);
        $this->rate($profile, 2);
        // Score 4 => media (5+2+4)/3 = 3.666... => 3.7
        $this->rate($profile, 4);

        $average = app(ProfileService::class)->calculateAverageRating($profile->fresh());

        $this->assertSame(3.7, $average);
    }

    public function test_calculate_average_rating_sin_ratings_retorna_null(): void
    {
        $profile = $this->makeProvider(['average_rating' => 4.5]);

        $average = app(ProfileService::class)->calculateAverageRating($profile);

        $this->assertNull($average);
        // El cache obsoleto se limpia a null.
        $this->assertDatabaseHas('provider_profiles', [
            'id' => $profile->id,
            'average_rating' => null,
        ]);
    }

    // --- Requisito 3.5: reseñas ordenadas por fecha descendente -----------

    public function test_resenas_ordenadas_por_fecha_descendente(): void
    {
        $profile = $this->makeProvider();

        $old = $this->rate($profile, 3, comment: 'La más antigua.');
        $old->forceFill(['created_at' => now()->subDays(5)])->save();

        $recent = $this->rate($profile, 5, comment: 'La más reciente.');
        $recent->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->getJson("/api/v1/providers/{$profile->id}")->assertOk();

        $reviews = $response->json('data.provider.reviews');

        $this->assertCount(2, $reviews);
        $this->assertSame($recent->id, $reviews[0]['id']);
        $this->assertSame($old->id, $reviews[1]['id']);
    }

    public function test_solo_resenas_visibles_aparecen_en_el_perfil(): void
    {
        $profile = $this->makeProvider();
        $this->rate($profile, 5, visible: true);
        $this->rate($profile, 1, visible: false);

        $response = $this->getJson("/api/v1/providers/{$profile->id}")->assertOk();

        $this->assertCount(1, $response->json('data.provider.reviews'));
        // El promedio, en cambio, considera TODAS las calificaciones recibidas
        // (5 y 1 => 3.0). JSON serializa 3.0 como 3, por eso se compara el valor.
        $this->assertEquals(3.0, $response->json('data.provider.average_rating'));
    }

    // --- Requisitos 9.2 / 9.4: Índice de Cumplimiento ---------------------

    public function test_indice_cumplimiento_sin_datos_suficientes_con_menos_de_tres_trabajos(): void
    {
        $profile = $this->makeProvider([
            'compliance_index' => 80,
            'completed_jobs_count' => 2,
        ]);

        $this->getJson("/api/v1/providers/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider.compliance_index', 'Sin datos suficientes')
            ->assertJsonPath('data.provider.completed_jobs_count', 2);
    }

    public function test_indice_cumplimiento_sin_datos_suficientes_sin_indice_calculado(): void
    {
        $profile = $this->makeProvider([
            'compliance_index' => null,
            'completed_jobs_count' => 5,
        ]);

        $this->getJson("/api/v1/providers/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider.compliance_index', 'Sin datos suficientes');
    }

    public function test_indice_cumplimiento_numerico_con_tres_o_mas_trabajos(): void
    {
        $profile = $this->makeProvider([
            'compliance_index' => 75,
            'completed_jobs_count' => 4,
        ]);

        $this->getJson("/api/v1/providers/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider.compliance_index', 75);
    }

    // --- Casos de borde: perfil inexistente / inactivo --------------------

    public function test_perfil_inexistente_retorna_404(): void
    {
        $this->getJson('/api/v1/providers/999999')->assertStatus(404);
    }

    public function test_perfil_inactivo_no_es_publico(): void
    {
        $profile = $this->makeProvider(['is_active' => false]);

        $this->getJson("/api/v1/providers/{$profile->id}")->assertStatus(404);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pruebas de los endpoints de verificación y reenvío de correo:
 *  - POST /api/v1/auth/email/verify/{id}/{hash}
 *  - POST /api/v1/auth/email/resend
 *
 * Cubre el Requisito 1.2 (flujo de verificación de correo).
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verifica_el_correo_con_hash_valido(): void
    {
        Event::fake();

        $user = User::factory()->unverified()->create();

        $hash = sha1($user->getEmailForVerification());

        $response = $this->postJson("/api/v1/auth/email/verify/{$user->id}/{$hash}");

        $response->assertOk()->assertJsonPath('data.email_verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_rechaza_verificacion_con_hash_invalido(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->postJson("/api/v1/auth/email/verify/{$user->id}/hash-incorrecto");

        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verificacion_de_usuario_inexistente_retorna_404(): void
    {
        $response = $this->postJson('/api/v1/auth/email/verify/999999/'.sha1('x@example.com'));

        $response->assertStatus(404);
    }

    public function test_reenvio_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/auth/email/resend')->assertStatus(401);
    }

    public function test_reenvio_envia_correo_a_usuario_no_verificado(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/resend')
            ->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_reenvio_no_envia_correo_si_ya_esta_verificado(): void
    {
        Notification::fake();

        $user = User::factory()->create(); // verificado por defecto

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/resend')
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);

        Notification::assertNothingSent();
    }
}

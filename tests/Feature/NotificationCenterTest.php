<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests para la tarea 14.2b: centro de notificaciones y marcado de
 * leídas (Requisitos 12.3, 12.4, 12.5, 12.7).
 *
 * Cubre:
 *  - 12.3: GET /notifications lista las notificaciones del usuario ordenadas
 *    por fecha descendente y acotadas a 200, con su estado leída/no leída.
 *  - 12.5: GET /notifications/unread-count devuelve el conteo de no leídas y
 *    el display "99+" cuando supera 100.
 *  - 12.4: PUT /notifications/{id}/read marca una notificación propia como
 *    leída (is_read=true, read_at set); 404 si no es del usuario.
 *  - 12.7: PUT /notifications/read-all marca todas las no leídas como leídas.
 *  - Todos los endpoints exigen autenticación (401 sin token).
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function makeNotification(
        User $user,
        string $type = 'quote.received',
        bool $isRead = false,
        ?string $createdAt = null,
    ): Notification {
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'data' => ['foo' => 'bar'],
            'is_read' => $isRead,
            'read_at' => $isRead ? now() : null,
        ]);

        if ($createdAt !== null) {
            $notification->forceFill(['created_at' => $createdAt])->save();
        }

        return $notification;
    }

    // --- Requisito 12.3: listado ordenado desc y acotado a 200 -------------

    public function test_lista_notificaciones_ordenadas_por_fecha_descendente(): void
    {
        $user = User::factory()->create();

        $older = $this->makeNotification($user, 'quote.received', false, now()->subDays(2)->toDateTimeString());
        $newest = $this->makeNotification($user, 'quote.accepted', false, now()->toDateTimeString());
        $middle = $this->makeNotification($user, 'job.completed', true, now()->subDay()->toDateTimeString());

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('data.notifications.0.id', $newest->id)
            ->assertJsonPath('data.notifications.1.id', $middle->id)
            ->assertJsonPath('data.notifications.2.id', $older->id);

        // Distingue leídas de no leídas (Requisito 12.3).
        $response->assertJsonPath('data.notifications.0.is_read', false)
            ->assertJsonPath('data.notifications.1.is_read', true);
    }

    public function test_lista_solo_devuelve_notificaciones_del_usuario(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->makeNotification($user);
        $this->makeNotification($other);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.id', $mine->id);
    }

    public function test_lista_se_acota_a_200_notificaciones(): void
    {
        $user = User::factory()->create();

        $rows = [];
        $base = now()->subMinutes(300);
        for ($i = 0; $i < 205; $i++) {
            $rows[] = [
                'user_id' => $user->id,
                'type' => 'quote.received',
                'data' => json_encode(['i' => $i]),
                'is_read' => false,
                'read_at' => null,
                'created_at' => $base->copy()->addMinutes($i),
                'updated_at' => $base->copy()->addMinutes($i),
            ];
        }
        Notification::insert($rows);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonCount(200, 'data.notifications');
    }

    // --- Requisito 12.5: contador de no leídas con tope "99+" --------------

    public function test_unread_count_devuelve_conteo_numerico_y_display(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->makeNotification($user, 'quote.received', false);
        }
        $this->makeNotification($user, 'quote.accepted', true);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications/unread-count');

        $response->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.display', '3');
    }

    public function test_unread_count_muestra_99_mas_cuando_supera_100(): void
    {
        $user = User::factory()->create();

        $rows = [];
        for ($i = 0; $i < 150; $i++) {
            $rows[] = [
                'user_id' => $user->id,
                'type' => 'quote.received',
                'data' => json_encode([]),
                'is_read' => false,
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Notification::insert($rows);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications/unread-count');

        $response->assertOk()
            ->assertJsonPath('data.count', 150)
            ->assertJsonPath('data.display', '99+');
    }

    // --- Requisito 12.4: marcar una notificación como leída ----------------

    public function test_marca_notificacion_propia_como_leida(): void
    {
        $user = User::factory()->create();
        $notification = $this->makeNotification($user, 'quote.received', false);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk()
            ->assertJsonPath('data.notification.id', $notification->id)
            ->assertJsonPath('data.notification.is_read', true);

        $this->assertNotNull($response->json('data.notification.read_at'));

        $notification->refresh();
        $this->assertTrue($notification->is_read);
        $this->assertNotNull($notification->read_at);
    }

    public function test_marcar_notificacion_ajena_devuelve_404(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->makeNotification($other, 'quote.received', false);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/notifications/{$foreign->id}/read");

        $response->assertNotFound();

        // La notificación ajena no se modifica.
        $foreign->refresh();
        $this->assertFalse($foreign->is_read);
        $this->assertNull($foreign->read_at);
    }

    public function test_marcar_notificacion_inexistente_devuelve_404(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/999999/read')->assertNotFound();
    }

    // --- Requisito 12.7: marcar todas como leídas --------------------------

    public function test_marca_todas_las_no_leidas_como_leidas(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makeNotification($user, 'quote.received', false);
        $this->makeNotification($user, 'quote.accepted', false);
        $alreadyRead = $this->makeNotification($user, 'job.completed', true);
        $foreign = $this->makeNotification($other, 'quote.received', false);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/notifications/read-all');

        $response->assertOk()
            ->assertJsonPath('data.updated', 2);

        // Todas las del usuario quedan leídas.
        $this->assertSame(
            0,
            Notification::where('user_id', $user->id)->where('is_read', false)->count(),
        );

        // Las ajenas no se tocan.
        $foreign->refresh();
        $this->assertFalse($foreign->is_read);
    }

    // --- Autenticación requerida en todos los endpoints --------------------

    public function test_endpoints_requieren_autenticacion(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
        $this->putJson('/api/v1/notifications/1/read')->assertUnauthorized();
        $this->putJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Events\NewNotification;
use App\Jobs\SendNotificationJob;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotifyingJobNotifier;
use App\Support\NotifyingQuoteNotifier;
use App\Support\NotifyingQuoteRequestNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests para la tarea 14.1: emisión asíncrona de notificaciones
 * (Requisitos 12.1, 12.2, 12.6).
 *
 * Cubre:
 *  - NotificationService::notify() inserta la notificación in-app (Requisito
 *    12.1) y despacha SendNotificationJob a la cola (Requisito 12.2).
 *  - SendNotificationJob transmite el evento NewNotification por Reverb al
 *    canal privado del Usuario (Requisito 12.1) y define la política de
 *    reintentos tries=3 / backoff=10s (Requisito 12.6).
 *  - Los adaptadores de los seams de los módulos (Solicitud, Cotización,
 *    Trabajo) producen filas de notificación con los destinatarios correctos.
 */
class NotificationServiceTest extends TestCase
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

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function category(string $slug = 'plomeria'): ServiceCategory
    {
        return ServiceCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug)],
        );
    }

    // --- Requisitos 12.1/12.2: notify() inserta fila y despacha el Job ------

    public function test_notify_inserta_notificacion_in_app_y_despacha_el_job(): void
    {
        Queue::fake();

        $user = $this->user();

        $notification = $this->service()->notify($user->id, 'quote.received', [
            'quote_id' => 42,
        ]);

        // Requisito 12.1: la notificación in-app queda persistida.
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'type' => 'quote.received',
            'is_read' => false,
        ]);
        $this->assertSame(['quote_id' => 42], $notification->data);
        $this->assertFalse($notification->is_read);

        // Requisito 12.2: el envío se delega a la cola de forma asíncrona.
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job): bool => $job->notificationId === $notification->id,
        );
    }

    public function test_notify_many_emite_una_notificacion_por_destinatario(): void
    {
        Queue::fake();

        $a = $this->user();
        $b = $this->user();

        $this->service()->notifyMany([$a->id, $b->id], 'quote_request.closed', [
            'quote_request_id' => 7,
        ]);

        $this->assertDatabaseHas('notifications', ['user_id' => $a->id, 'type' => 'quote_request.closed']);
        $this->assertDatabaseHas('notifications', ['user_id' => $b->id, 'type' => 'quote_request.closed']);

        Queue::assertPushed(SendNotificationJob::class, 2);
    }

    // --- Requisito 12.1: el Job transmite NewNotification por Reverb --------

    public function test_send_notification_job_transmite_new_notification(): void
    {
        Event::fake([NewNotification::class]);

        $user = $this->user();
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'rating.received',
            'data' => ['job_id' => 3],
            'is_read' => false,
        ]);

        (new SendNotificationJob($notification->id))->handle();

        Event::assertDispatched(
            NewNotification::class,
            fn (NewNotification $event): bool => $event->notification->id === $notification->id,
        );
    }

    public function test_send_notification_job_ignora_notificacion_inexistente(): void
    {
        Event::fake([NewNotification::class]);

        (new SendNotificationJob(999999))->handle();

        Event::assertNotDispatched(NewNotification::class);
    }

    // --- Requisito 12.6: política de reintentos (3 intentos / 10 s) ---------

    public function test_send_notification_job_define_reintentos_y_backoff(): void
    {
        $job = new SendNotificationJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->backoff());
    }

    // --- Requisito 12.1: el evento transmite por el canal privado del user --

    public function test_new_notification_transmite_por_canal_privado_del_usuario(): void
    {
        $user = $this->user();
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'job.completion_requested',
            'data' => ['job_id' => 10],
            'is_read' => false,
        ]);

        $event = new NewNotification($notification);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-user.'.$user->id, $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame($notification->id, $payload['id']);
        $this->assertSame('job.completion_requested', $payload['type']);
        $this->assertSame(['job_id' => 10], $payload['data']);
    }

    // --- Adaptadores de los seams producen filas de notificación -----------

    public function test_adaptador_de_cotizacion_notifica_al_cliente_receptor(): void
    {
        Queue::fake();

        $provider = $this->user();
        $client = $this->user();
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);
        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio',
            'total_price' => 100.00,
            'delivery_days' => 5,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);

        app(NotifyingQuoteNotifier::class)->notifyClientOfNewQuote($quote);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $client->id,
            'type' => 'quote.received',
        ]);
    }

    public function test_adaptador_de_solicitud_notifica_al_cliente_de_la_postulacion(): void
    {
        Queue::fake();

        $provider = $this->user();
        $client = $this->user();
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        app(NotifyingQuoteRequestNotifier::class)
            ->notifyClientOfApplication($request, $provider->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $client->id,
            'type' => 'quote_request.application_received',
        ]);
    }

    public function test_adaptador_de_trabajo_notifica_al_cliente_de_la_finalizacion(): void
    {
        Queue::fake();

        $provider = $this->user();
        $client = $this->user();
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_EN_PROCESO,
        ]);
        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio',
            'total_price' => 100.00,
            'delivery_days' => 5,
            'expires_at' => now()->addDays(7),
            'status' => Quote::STATUS_ACEPTADA,
        ]);
        $job = Job::create([
            'quote_id' => $quote->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'status' => Job::STATUS_EN_PROGRESO,
            'start_date' => now()->toDateString(),
            'estimated_delivery_date' => now()->addDays(5)->toDateString(),
        ]);

        app(NotifyingJobNotifier::class)->notifyClientOfCompletion($job);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $client->id,
            'type' => 'job.completion_requested',
        ]);
    }
}

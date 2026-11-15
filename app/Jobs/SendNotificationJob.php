<?php

namespace App\Jobs;

use App\Events\NewNotification;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío asíncrono de una notificación in-app (tarea 14.1, Requisitos 12.1,
 * 12.2, 12.6).
 *
 * Lo despacha NotificationService::notify() tras persistir la fila de la
 * notificación, de modo que la operación de negocio que la origina (postular,
 * cotizar, completar un Trabajo, calificar, chatear) no se bloquea esperando el
 * broadcast (Requisito 12.2: procesamiento asíncrono por colas de Redis). Al
 * ejecutarse:
 *
 *  - Recarga la Notificación desde la base de datos (se referencia por id, no
 *    por instancia serializada, para leer el estado fresco en ejecución).
 *  - Transmite el evento NewNotification por Reverb al canal privado
 *    `private-user.{userId}` del destinatario (Requisito 12.1).
 *
 * REINTENTOS Y DESCARTE (Requisito 12.6): si el envío falla, la cola lo
 * reintenta hasta `$tries` (3) veces con `backoff()` (10 s) entre intentos. Si
 * se agotan los reintentos, Laravel invoca failed(), que registra el descarte
 * en el log sin más reintentos.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número máximo de intentos antes de descartar la notificación
     * (Requisito 12.6: hasta 3 reintentos del envío inicial).
     */
    public int $tries = 3;

    public function __construct(public readonly int $notificationId)
    {
    }

    /**
     * Segundos de espera entre reintentos (Requisito 12.6: intervalos de 10 s).
     *
     * @return int|array<int, int>
     */
    public function backoff(): int|array
    {
        return 10;
    }

    /**
     * Transmite la notificación al canal privado del Usuario destinatario
     * (Requisito 12.1). Un fallo aquí propaga la excepción para que la cola
     * aplique la política de reintentos (Requisito 12.6).
     */
    public function handle(): void
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()->find($this->notificationId);

        // La notificación pudo eliminarse mientras el Job esperaba en la cola;
        // no hay nada que transmitir.
        if ($notification === null) {
            return;
        }

        NewNotification::dispatch($notification);
    }

    /**
     * Se invoca cuando se agotan los reintentos (Requisito 12.6): registra el
     * descarte de la notificación sin reintentos adicionales.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('[SendNotificationJob] Notificación descartada tras agotar los reintentos.', [
            'notification_id' => $this->notificationId,
            'tries' => $this->tries,
            'exception' => $exception?->getMessage(),
        ]);
    }
}

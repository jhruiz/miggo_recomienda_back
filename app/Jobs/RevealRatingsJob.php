<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\Rating;
use App\Services\RatingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Revelación por tiempo de las Calificaciones de un Trabajo (tarea 12.5,
 * Requisito 8.5 — doble ciego).
 *
 * Se despacha al habilitar las Calificaciones (RatingService::enableRatings)
 * con un delay de 14 días desde la habilitación, siguiendo la misma estrategia
 * que ExpireQuoteJob/AutoCompleteJobJob (decisión técnica 1 del diseño: Jobs con
 * delay en lugar de comandos programados). Al ejecutarse:
 *
 *  - Recarga el Trabajo desde la base de datos (su estado y sus Calificaciones
 *    pudieron cambiar mientras el Job esperaba en la cola).
 *  - Revela (is_visible=true) las Calificaciones que YA fueron emitidas
 *    (rated_at no nulo), sin importar si solo una de las partes calificó: a los
 *    14 días la ventana de ocultamiento del doble ciego se levanta (Req 8.5).
 *  - Es un no-op si las Calificaciones emitidas ya son visibles (porque AMBAS
 *    partes calificaron antes de los 14 días y el servicio ya las reveló): este
 *    es el patrón de "cancelación" aceptado para Jobs con delay —ver nota de
 *    diseño sobre la auto-cancelación por guarda de estado.
 *
 * CANCELACIÓN DEL JOB (consistencia con ExpireQuoteJob/AutoCompleteJobJob):
 * Las colas de Laravel no permiten borrar fácilmente un Job ya encolado. En su
 * lugar, cuando AMBAS partes califican antes de los 14 días, RatingService
 * revela ambas Calificaciones de inmediato; cuando este Job finalmente se
 * ejecuta encuentra las filas ya visibles y, al re-aplicar is_visible=true
 * sobre filas ya emitidas, no cambia nada observable (idempotente). Así el Job
 * queda "cancelado" de facto mediante una guarda de estado, sin tocar la cola.
 *
 * Se referencia el Trabajo por id (no por instancia serializada) para que el
 * estado se lea siempre fresco en el momento de ejecución.
 */
class RevealRatingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $jobId)
    {
    }

    /**
     * Revela las Calificaciones emitidas del Trabajo al cumplirse los 14 días
     * (Requisito 8.5). Idempotente: no vuelve a tocar las ya visibles.
     */
    public function handle(): void
    {
        /** @var Job|null $job */
        $job = Job::query()->find($this->jobId);

        // El Trabajo pudo eliminarse mientras el Job esperaba en la cola.
        if ($job === null) {
            return;
        }

        // Guarda defensiva (consistente con ExpireQuoteJob): solo se revela por
        // tiempo cuando los 14 días ya transcurrieron. Protege contra la
        // ejecución temprana del Job (p. ej. el driver `sync` ignora el delay):
        // si aún no es la fecha de revelación, no se modifica nada y el Job
        // reprogramado por delay hará el trabajo real a los 14 días.
        $enablement = $job->ratings()->first();

        if ($enablement === null || $enablement->enabled_at === null) {
            return;
        }

        $revealAt = $enablement->enabled_at->copy()->addDays(RatingService::REVEAL_DAYS);

        if ($revealAt->isFuture()) {
            return;
        }

        // Revela solo las Calificaciones efectivamente emitidas y aún ocultas.
        // Si ambas ya se revelaron (ambas partes calificaron antes), el update
        // afecta a 0 filas: no-op (idempotente).
        $job->ratings()
            ->whereNotNull('rated_at')
            ->where('is_visible', false)
            ->update(['is_visible' => true]);
    }
}

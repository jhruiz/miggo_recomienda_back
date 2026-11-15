<?php

namespace App\Jobs;

use App\Models\Job;
use App\Services\RatingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cierre de la ventana de calificación de un Trabajo (tarea 12.5, Requisito 8.7).
 *
 * Se despacha al habilitar las Calificaciones (RatingService::enableRatings)
 * con un delay de 30 días desde la habilitación, siguiendo la misma estrategia
 * que ExpireQuoteJob/AutoCompleteJobJob (decisión técnica 1 del diseño).
 *
 * NOTA DE DISEÑO — por qué este Job es (deliberadamente) un no-op de estado:
 * La regla dura de "ventana cerrada a los 30 días" ya la impone
 * RatingService::submitRating comparando `now()` contra `window_closes_at` en
 * cada intento de emisión (Requisito 8.7). Es decir, el cierre NO depende de
 * que corra ningún Job: una Calificación tardía se rechaza aunque este Job
 * nunca llegue a ejecutarse. Este Job existe para dejar un hito explícito del
 * cierre de la ventana (log/observabilidad) sin alterar datos, de modo que:
 *  - no rompe nada si se ejecuta (idempotente, sin efectos sobre `ratings`);
 *  - es seguro bajo el driver `sync` de los tests (ejecución inmediata), porque
 *    solo registra la intención de cierre.
 *
 * Se referencia el Trabajo por id (no por instancia serializada) para leer el
 * estado fresco en el momento de ejecución.
 */
class CloseRatingWindowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $jobId)
    {
    }

    /**
     * Marca (log) el cierre de la ventana de 30 días. No modifica datos: el
     * corte efectivo de emisiones lo garantiza submitRating (Requisito 8.7).
     */
    public function handle(): void
    {
        /** @var Job|null $job */
        $job = Job::query()->find($this->jobId);

        // El Trabajo pudo eliminarse mientras el Job esperaba en la cola.
        if ($job === null) {
            return;
        }

        $enablement = $job->ratings()->first();

        if ($enablement === null || $enablement->window_closes_at === null) {
            return;
        }

        // Guarda defensiva (consistente con ExpireQuoteJob): solo registra el
        // cierre cuando la fecha ya se alcanzó. Bajo el driver `sync` el delay
        // se ignora, así que sin esta guarda se registraría un cierre prematuro.
        if ($enablement->window_closes_at->isFuture()) {
            return;
        }

        Log::info('Ventana de calificación cerrada (Requisito 8.7).', [
            'job_id' => $job->id,
            'window_closes_at' => $enablement->window_closes_at->toIso8601String(),
            'window_days' => RatingService::WINDOW_DAYS,
        ]);
    }
}

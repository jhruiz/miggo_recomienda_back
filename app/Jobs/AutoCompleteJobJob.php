<?php

namespace App\Jobs;

use App\Contracts\JobCompletionHandler;
use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-completado de un Trabajo tras 7 días sin respuesta del Cliente
 * (tarea 9.2, Requisito 7.5).
 *
 * Se despacha cuando el Prestador marca el Trabajo como completado
 * (JobService::markAsCompleted) con un delay de 7 días calendario, siguiendo la
 * misma estrategia que ExpireQuoteJob (decisión técnica 1 del diseño: Jobs con
 * delay en lugar de comandos programados). Al ejecutarse:
 *
 *  - Recarga el Trabajo desde la base de datos (su estado pudo cambiar mientras
 *    el Job esperaba en la cola: el Cliente pudo confirmar o rechazar).
 *  - Si sigue "en_progreso" (el Cliente no respondió), lo pasa a "completado" e
 *    invoca el seam de finalización (habilitar Calificaciones + recalcular
 *    Índice_Cumplimiento, tareas 12.1/9.6) — Requisito 7.5.
 *  - Si NO está "en_progreso" (ya "completado" por confirmación, o "cancelado"),
 *    no modifica nada: misma guarda que ExpireQuoteJob (Requisito 7.4). Esto
 *    hace que, al confirmar, el auto-complete pendiente quede efectivamente
 *    cancelado sin necesidad de borrarlo de la cola.
 *
 * Se referencia el Trabajo por id (no por instancia serializada) para que el
 * estado se lea siempre fresco en el momento de ejecución.
 */
class AutoCompleteJobJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $jobId)
    {
    }

    /**
     * Completa automáticamente el Trabajo solo si sigue "en_progreso"; en otro
     * caso es un no-op (el Cliente ya confirmó/rechazó, o el Trabajo se
     * canceló) — Requisito 7.5.
     */
    public function handle(JobCompletionHandler $completionHandler): void
    {
        /** @var Job|null $job */
        $job = Job::query()->find($this->jobId);

        // El Trabajo pudo eliminarse mientras el Job esperaba en la cola.
        if ($job === null) {
            return;
        }

        // Solo auto-completa un Trabajo que SIGA a la espera de respuesta del
        // Cliente: "en_progreso" con la finalización ya marcada por el Prestador
        // (`actual_completion_date` fijada). Esta doble guarda:
        //  - Hace no-op tras una confirmación (estado "completado") o una
        //    cancelación (estado "cancelado") — Requisito 7.4.
        //  - Hace no-op tras un rechazo: el Trabajo vuelve a "en_progreso" pero
        //    con la fecha de finalización limpiada, de modo que este Job
        //    despachado antes del rechazo no complete el Trabajo indebidamente
        //    (Requisito 7.6). Al volver a marcar la finalización, se despacha un
        //    AutoCompleteJobJob nuevo.
        if ($job->status !== Job::STATUS_EN_PROGRESO || $job->actual_completion_date === null) {
            return;
        }

        $job->status = Job::STATUS_COMPLETADO;
        $job->save();

        // Habilita Calificaciones y recalcula el Índice_Cumplimiento vía el
        // seam compartido con la confirmación manual (Requisito 7.5).
        $completionHandler->onCompleted($job);
    }
}

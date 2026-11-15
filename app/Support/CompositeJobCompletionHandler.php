<?php

namespace App\Support;

use App\Contracts\JobCompletionHandler;
use App\Models\Job;

/**
 * Composición de varios JobCompletionHandler en uno solo (patrón composite).
 *
 * El flujo de finalización de un Trabajo (JobService::confirmCompletion y
 * AutoCompleteJobJob) invoca un único seam JobCompletionHandler. Pero varios
 * efectos distintos deben ocurrir al completar un Trabajo:
 *   - Recalcular/cachear el Índice_Cumplimiento (tarea 9.6 → ComplianceJobCompletionHandler).
 *   - Habilitar las Calificaciones (tarea 12.1 → su propio handler).
 *
 * En lugar de que cada tarea reemplace el binding —pisando el efecto de la
 * otra— se componen aquí. Cada handler se ejecuta en orden; se invoca
 * exactamente una vez por transición a "completado". Así, la tarea 12.1 solo
 * tiene que registrar su handler como un elemento más de esta composición en
 * AppServiceProvider, sin modificar ComplianceJobCompletionHandler, JobService
 * ni AutoCompleteJobJob.
 */
class CompositeJobCompletionHandler implements JobCompletionHandler
{
    /** @var list<JobCompletionHandler> */
    private array $handlers;

    public function __construct(JobCompletionHandler ...$handlers)
    {
        $this->handlers = array_values($handlers);
    }

    public function onCompleted(Job $job): void
    {
        foreach ($this->handlers as $handler) {
            $handler->onCompleted($job);
        }
    }
}

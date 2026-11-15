<?php

namespace App\Support;

use App\Contracts\JobCompletionHandler;
use App\Models\Job;
use Illuminate\Support\Facades\Log;

/**
 * Implementación provisional (placeholder) del seam de efectos de finalización
 * de un Trabajo (ver App\Contracts\JobCompletionHandler).
 *
 * No habilita Calificaciones (tarea 12.1) ni recalcula el Índice_Cumplimiento
 * (tarea 9.6) porque esos componentes aún no existen. Se limita a registrar que
 * la finalización ocurrió, dejando el punto de extensión listo y verificable
 * para la tarea 9.2 sin anticipar lógica de otras tareas.
 *
 * La tarea 12.1 y la tarea 9.6 deben reemplazar (o componer) el binding de
 * JobCompletionHandler en AppServiceProvider.
 */
class NullJobCompletionHandler implements JobCompletionHandler
{
    public function onCompleted(Job $job): void
    {
        Log::info('[JobCompletionHandler placeholder] job.completed', [
            'job_id' => $job->id,
            'provider_user_id' => $job->provider_user_id,
            'client_user_id' => $job->client_user_id,
            // Pendiente (seams): habilitar Calificaciones (tarea 12.1) y
            // recalcular Índice_Cumplimiento del Prestador (tarea 9.6).
        ]);
    }
}

<?php

namespace App\Support;

use App\Contracts\JobCompletionHandler;
use App\Models\Job;
use App\Services\JobService;
use Closure;

/**
 * Implementación del seam de finalización (App\Contracts\JobCompletionHandler)
 * que recalcula y cachea el Índice_Cumplimiento del Prestador cuando un Trabajo
 * pasa a "completado" (tarea 9.6, Requisitos 9.1, 9.3, 9.5, 9.6).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 9.6 / tarea 12.1)
 * -----------------------------------------------------------------------------
 * Este handler se ocupa ÚNICAMENTE del Índice_Cumplimiento. La habilitación de
 * las Calificaciones al completar un Trabajo (tarea 12.1, Requisito 7.4/8.1) es
 * otro efecto de finalización independiente. Para que ambos efectos coexistan
 * sin que uno pise al otro, el binding en AppServiceProvider compone los
 * handlers mediante CompositeJobCompletionHandler: la tarea 12.1 solo tiene que
 * añadir su propio handler a la composición, sin tocar este ni JobService.
 * -----------------------------------------------------------------------------
 * RESOLUCIÓN DIFERIDA (dependencia circular)
 * -----------------------------------------------------------------------------
 * El cálculo del Índice_Cumplimiento vive en JobService, pero JobService
 * depende a su vez de JobCompletionHandler (el seam de finalización) en su
 * constructor. Inyectar JobService directamente aquí crearía un ciclo de
 * resolución en el contenedor (JobCompletionHandler → ComplianceJobCompletionHandler
 * → JobService → JobCompletionHandler → ...). Para romperlo, se recibe una
 * factoría perezosa (Closure) que resuelve JobService SOLO al invocar
 * onCompleted(), momento en que el JobCompletionHandler ya está construido y
 * cacheado (binding singleton), de modo que no se vuelve a entrar al ciclo.
 * -----------------------------------------------------------------------------
 *
 * @phpstan-type JobServiceFactory Closure(): JobService
 */
class ComplianceJobCompletionHandler implements JobCompletionHandler
{
    /** @var Closure(): JobService */
    private Closure $jobServiceFactory;

    /**
     * @param  Closure(): JobService  $jobServiceFactory  Factoría perezosa de JobService.
     */
    public function __construct(Closure $jobServiceFactory)
    {
        $this->jobServiceFactory = $jobServiceFactory;
    }

    /**
     * Al completarse el Trabajo, recalcula el Índice_Cumplimiento cacheado del
     * Prestador correspondiente, dejándolo listo antes de que el Perfil_Prestador
     * sea consultado tras el evento (Requisito 9.3).
     */
    public function onCompleted(Job $job): void
    {
        ($this->jobServiceFactory)()->recalculateComplianceIndex($job->provider_user_id);
    }
}

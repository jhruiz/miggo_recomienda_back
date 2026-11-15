<?php

namespace App\Support;

use App\Contracts\JobCompletionHandler;
use App\Models\Job;
use App\Services\RatingService;
use Closure;

/**
 * Implementación del seam de finalización (App\Contracts\JobCompletionHandler)
 * que habilita las Calificaciones de un Trabajo cuando pasa a "completado"
 * (tarea 12.1, Requisitos 7.4, 8.1).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 12.1 / tarea 9.6)
 * -----------------------------------------------------------------------------
 * Este handler se ocupa ÚNICAMENTE de habilitar las Calificaciones. El
 * recálculo del Índice_Cumplimiento es otro efecto de finalización
 * independiente (ComplianceJobCompletionHandler, tarea 9.6). Ambos coexisten
 * porque el binding en AppServiceProvider los compone mediante
 * CompositeJobCompletionHandler: este handler se añade como un elemento más de
 * la composición, sin tocar JobService, AutoCompleteJobJob ni el handler de
 * cumplimiento.
 * -----------------------------------------------------------------------------
 * RESOLUCIÓN DIFERIDA (consistencia con el handler de cumplimiento)
 * -----------------------------------------------------------------------------
 * Se recibe una factoría perezosa (Closure) que resuelve RatingService SOLO al
 * invocar onCompleted(). Se sigue el mismo patrón que
 * ComplianceJobCompletionHandler para evitar que la construcción del seam
 * (binding singleton) arrastre la resolución anticipada de servicios y por
 * robustez ante futuros ciclos de dependencia.
 *
 * @phpstan-type RatingServiceFactory Closure(): RatingService
 */
class RatingsJobCompletionHandler implements JobCompletionHandler
{
    /** @var Closure(): RatingService */
    private Closure $ratingServiceFactory;

    /**
     * @param  Closure(): RatingService  $ratingServiceFactory  Factoría perezosa de RatingService.
     */
    public function __construct(Closure $ratingServiceFactory)
    {
        $this->ratingServiceFactory = $ratingServiceFactory;
    }

    /**
     * Al completarse el Trabajo, habilita la emisión de Calificaciones para
     * ambas partes y fija la ventana de 30 días (Requisitos 7.4, 8.1).
     */
    public function onCompleted(Job $job): void
    {
        ($this->ratingServiceFactory)()->enableRatings($job);
    }
}

<?php

namespace App\Contracts;

use App\Models\Job;

/**
 * Seam de efectos colaterales que ocurren cuando un Trabajo alcanza el estado
 * "completado" (Requisitos 7.4, 7.5; también 8.1 y 9.1/9.6).
 *
 * Un Trabajo puede completarse por dos caminos que comparten exactamente los
 * mismos efectos:
 *   - El Cliente confirma la finalización (Requisito 7.4), vía
 *     JobService::confirmCompletion().
 *   - Transcurren 7 días sin respuesta y AutoCompleteJobJob lo completa de
 *     forma automática (Requisito 7.5).
 *
 * En ambos casos, al pasar a "completado" deben:
 *   1. Habilitarse las Calificaciones del Trabajo (Requisito 7.4/8.1). La
 *      lógica real de RatingService::enableRatings() es de la tarea 12.1.
 *   2. Recalcularse el Índice_Cumplimiento cacheado del Prestador
 *      (Requisito 9.4/9.6). El cálculo real es de la tarea 9.6.
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 9.2 / tareas 12.1 y 9.6)
 * -----------------------------------------------------------------------------
 * Ni RatingService (tarea 12.1) ni el cálculo del Índice_Cumplimiento
 * (tarea 9.6) existen todavía. Para no bloquear la tarea 9.2 ni duplicar su
 * lógica, la finalización de un Trabajo invoca este único seam, resuelto hoy a
 * un placeholder no-op (NullJobCompletionHandler) registrado en
 * AppServiceProvider.
 *
 * - La tarea 12.1 reemplazará (o decorará) el binding para habilitar las
 *   Calificaciones en onCompleted().
 * - La tarea 9.6 hará lo propio para recalcular/cachear el Índice_Cumplimiento.
 *
 * Ambas tareas pueden componer sus implementaciones sin tocar JobService ni
 * AutoCompleteJobJob: el punto de extensión ya está en su sitio.
 * -----------------------------------------------------------------------------
 */
interface JobCompletionHandler
{
    /**
     * Se invoca una vez, justo después de que un Trabajo transiciona a
     * "completado" (por confirmación del Cliente o por auto-completado).
     *
     * Las implementaciones deben ser idempotentes respecto al Trabajo: se
     * llama exactamente una vez por transición, pero conviene no asumir orden
     * ni reintentos cero.
     */
    public function onCompleted(Job $job): void;
}

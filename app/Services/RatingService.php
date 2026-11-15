<?php

namespace App\Services;

use App\Exceptions\RatingSubmissionException;
use App\Jobs\CloseRatingWindowJob;
use App\Jobs\RevealRatingsJob;
use App\Models\Job;
use App\Models\Rating;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de Calificaciones (Gestor_Calificaciones del dominio).
 *
 * Cubre la tarea 12.1 (Requisitos 8.1, 8.2, 8.3, 8.4, 8.6, 8.7):
 *   - enableRatings(): al completarse un Trabajo, habilita la emisión de
 *     Calificaciones para ambas partes y fija la referencia temporal de la
 *     ventana (enabled_at y window_closes_at = enabled_at + 30 días).
 *   - submitRating(): persiste la Calificación de un Usuario sobre su
 *     contraparte, rechazando duplicadas (8.6) y envíos fuera de la ventana de
 *     30 días (8.7). La validación de score/comentario (8.2, 8.3) la hace el
 *     Form Request antes de llegar aquí.
 *   - ratingsForJob(): lista las Calificaciones de un Trabajo.
 *
 * -----------------------------------------------------------------------------
 * REPRESENTACIÓN DE LA VENTANA DE CALIFICACIÓN
 * -----------------------------------------------------------------------------
 * Al habilitar las Calificaciones se crean DOS filas placeholder en `ratings`
 * (una por cada dirección: Cliente→Prestador y Prestador→Cliente). Cada fila
 * guarda `enabled_at` y `window_closes_at` (+30 días), que son la referencia
 * común para los plazos de cierre (Requisito 8.7) y de revelación (tarea 12.5).
 * Mientras la fila no tiene `rated_at`, representa una Calificación "habilitada
 * pero no emitida" (score sentinela 0). Al emitir, la MISMA fila se completa
 * con score (1-5), comentario y `rated_at`.
 *
 * Esta representación aprovecha el índice único (job_id, rater_user_id) para
 * garantizar a nivel de datos una única Calificación por par (Usuario, Trabajo)
 * —Requisito 8.6— y mantiene la referencia de ventana por calificador, sin
 * depender de columnas extra en el Trabajo.
 * -----------------------------------------------------------------------------
 */
class RatingService
{
    /** Días de la ventana de calificación desde la habilitación (Requisito 8.7). */
    public const WINDOW_DAYS = 30;

    /**
     * Días tras la habilitación en los que, si no han calificado AMBAS partes,
     * las Calificaciones emitidas se revelan igualmente (doble ciego, Req 8.5).
     */
    public const REVEAL_DAYS = 14;

    /** Score sentinela de una Calificación habilitada pero aún no emitida. */
    public const UNRATED_SCORE = 0;

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Habilita las Calificaciones de un Trabajo recién "completado"
     * (Requisitos 8.1, 7.4). Crea las filas placeholder de ambas partes con la
     * referencia temporal de la ventana (enabled_at, window_closes_at = +30d).
     *
     * Es idempotente: si ya existen filas de calificación para el Trabajo (p.
     * ej. por un segundo evento de finalización), no las duplica ni reinicia la
     * ventana. Se invoca a través del seam JobCompletionHandler, por lo que no
     * acopla a JobService.
     */
    public function enableRatings(Job $job): void
    {
        $justEnabled = DB::transaction(function () use ($job): bool {
            // Idempotencia: si ya se habilitaron, no reabrir la ventana.
            if ($job->ratings()->exists()) {
                return false;
            }

            $enabledAt = now();
            $windowClosesAt = $enabledAt->copy()->addDays(self::WINDOW_DAYS);

            // Cliente califica al Prestador y Prestador califica al Cliente.
            $pairs = [
                ['rater' => $job->client_user_id, 'rated' => $job->provider_user_id],
                ['rater' => $job->provider_user_id, 'rated' => $job->client_user_id],
            ];

            foreach ($pairs as $pair) {
                Rating::create([
                    'job_id' => $job->id,
                    'rater_user_id' => $pair['rater'],
                    'rated_user_id' => $pair['rated'],
                    'score' => self::UNRATED_SCORE,
                    'comment' => null,
                    'is_visible' => false,
                    'rated_at' => null,
                    'enabled_at' => $enabledAt,
                    'window_closes_at' => $windowClosesAt,
                ]);
            }

            return true;
        });

        // Solo se programan los Jobs de doble ciego la PRIMERA vez que se
        // habilitan las Calificaciones (idempotencia: una segunda finalización
        // no duplica los Jobs). Se despachan FUERA de la transacción para que,
        // bajo el driver `sync` de los tests, el Job vea las filas ya
        // persistidas; la guarda isFuture() de cada Job lo convierte en no-op
        // cuando sync ignora el delay.
        if (! $justEnabled) {
            return;
        }

        // Revelación por tiempo del doble ciego a los 14 días (Requisito 8.5).
        RevealRatingsJob::dispatch($job->id)->delay(now()->addDays(self::REVEAL_DAYS));

        // Hito de cierre de la ventana a los 30 días (Requisito 8.7). El corte
        // efectivo de emisiones ya lo impone submitRating; este Job solo deja
        // constancia del cierre.
        CloseRatingWindowJob::dispatch($job->id)->delay(now()->addDays(self::WINDOW_DAYS));
    }

    /**
     * Emite la Calificación del Usuario autenticado sobre su contraparte en el
     * Trabajo (Requisitos 8.2, 8.4, 8.6, 8.7).
     *
     * El score (entero 1-5) y el comentario (10-500) ya vienen validados por el
     * Form Request. Aquí se aplican las reglas de estado del dominio:
     *   - Las Calificaciones deben estar habilitadas (Trabajo "completado") →
     *     RatingSubmissionException::notEnabled() (409).
     *   - El Usuario debe ser parte del Trabajo →
     *     RatingSubmissionException::notParticipant() (403).
     *   - No debe existir ya una Calificación emitida por el Usuario →
     *     RatingSubmissionException::alreadyRated() (409).
     *   - La ventana de 30 días no debe haberse cerrado →
     *     RatingSubmissionException::windowClosed() (409).
     *
     * La Calificación queda asociada al Trabajo y al par (rater, rated) correcto
     * (Requisitos 8.2, 8.4) e inicia con is_visible=false (la revelación de
     * doble ciego es la tarea 12.5).
     *
     * @throws RatingSubmissionException
     */
    public function submitRating(User $rater, Job $job, int $score, string $comment): Rating
    {
        return DB::transaction(function () use ($rater, $job, $score, $comment): Rating {
            // Las Calificaciones solo se habilitan al completar el Trabajo
            // (Requisito 8.1). Sin filas placeholder no hay ventana abierta.
            $enablement = $job->ratings()->first();

            if ($enablement === null) {
                throw RatingSubmissionException::notEnabled();
            }

            // El calificador debe ser una de las partes del Trabajo.
            if (! in_array($rater->id, [$job->client_user_id, $job->provider_user_id], true)) {
                throw RatingSubmissionException::notParticipant();
            }

            // La fila placeholder del calificador (bloqueada para evitar
            // carreras entre dos envíos simultáneos del mismo Usuario).
            $rating = $job->ratings()
                ->where('rater_user_id', $rater->id)
                ->lockForUpdate()
                ->first();

            // Defensa: si no existiera la fila del calificador (no debería pasar
            // tras enableRatings), se trata como no participante.
            if ($rating === null) {
                throw RatingSubmissionException::notParticipant();
            }

            // Duplicada: ya emitió su Calificación (rated_at marcada) —Req 8.6.
            if ($rating->rated_at !== null) {
                throw RatingSubmissionException::alreadyRated();
            }

            // Ventana cerrada: 30 días desde la habilitación (Requisito 8.7).
            if ($rating->window_closes_at !== null && now()->greaterThan($rating->window_closes_at)) {
                throw RatingSubmissionException::windowClosed();
            }

            $rating->fill([
                'rated_user_id' => $rater->id === $job->client_user_id
                    ? $job->provider_user_id
                    : $job->client_user_id,
                'score' => $score,
                'comment' => $comment,
                'rated_at' => now(),
            ]);
            $rating->save();

            // Doble ciego (Requisito 8.5): si AMBAS partes ya emitieron su
            // Calificación, se revelan ambas de inmediato. Esta revelación
            // temprana es también la "cancelación" de facto del RevealRatingsJob
            // de 14 días: cuando ese Job corra, encontrará las filas ya visibles
            // y su update será un no-op (ver RevealRatingsJob).
            $this->revealIfBothRated($job);

            // Notifica al Usuario calificado que ha recibido una nueva
            // Calificación (Requisito 12.1: "nueva Calificación recibida"). Se
            // emite in-app y de forma asíncrona vía NotificationService; no
            // revela el contenido para respetar el doble ciego hasta la
            // revelación (Requisito 8.5).
            $this->notifications->notify($rating->rated_user_id, 'rating.received', [
                'job_id' => $job->id,
                'rater_user_id' => $rating->rater_user_id,
                'actor_name' => $rater->name,
            ]);

            return $rating->refresh();
        });
    }

    /**
     * Revela (is_visible=true) AMBAS Calificaciones del Trabajo cuando las dos
     * partes ya las emitieron (doble ciego, Requisito 8.5).
     *
     * Mientras falte una por emitir, ninguna se revela: así ningún Usuario ve
     * la Calificación de su contraparte antes de emitir la propia. La operación
     * es idempotente: si ya estaban visibles, el update no cambia nada.
     */
    private function revealIfBothRated(Job $job): void
    {
        $submitted = $job->ratings()->whereNotNull('rated_at')->count();

        // Se necesitan las dos direcciones emitidas (Cliente→Prestador y
        // Prestador→Cliente) para levantar el doble ciego.
        if ($submitted < 2) {
            return;
        }

        $job->ratings()
            ->whereNotNull('rated_at')
            ->where('is_visible', false)
            ->update(['is_visible' => true]);
    }

    /**
     * Lista las Calificaciones de un Trabajo (GET /ratings/job/{job_id}).
     *
     * Devuelve únicamente Calificaciones ya emitidas (rated_at no nulo),
     * excluyendo las filas placeholder de habilitación. El control de acceso
     * (solo las partes del Trabajo) lo aplica el controlador.
     *
     * @return Collection<int, Rating>
     */
    public function ratingsForJob(Job $job): Collection
    {
        return $job->ratings()
            ->whereNotNull('rated_at')
            ->orderByDesc('rated_at')
            ->orderByDesc('id')
            ->get();
    }
}

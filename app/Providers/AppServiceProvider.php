<?php

namespace App\Providers;

use App\Contracts\JobCompletionHandler;
use App\Contracts\JobNotifier;
use App\Contracts\QuoteNotifier;
use App\Contracts\QuoteRequestNotifier;
use App\Http\Responses\ApiResponse;
use App\Models\Job;
use App\Models\User;
use App\Services\JobService;
use App\Services\RatingService;
use App\Support\ComplianceJobCompletionHandler;
use App\Support\CompositeJobCompletionHandler;
use App\Support\LoginRateLimiter;
use App\Support\NotifyingJobNotifier;
use App\Support\NotifyingQuoteNotifier;
use App\Support\NotifyingQuoteRequestNotifier;
use App\Support\RatingsJobCompletionHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Seams de notificación de los módulos (Solicitudes_Cotización,
        // Cotizaciones, Trabajos). Desde la tarea 14.1 se resuelven a
        // adaptadores que delegan en el Gestor_Notificaciones real
        // (NotificationService): persisten la notificación in-app (Requisito
        // 12.1) y despachan su envío asíncrono por Reverb vía
        // SendNotificationJob (Requisitos 12.2, 12.6). Los placeholders Log*
        // originales quedaron sustituidos sin tocar QuoteRequestService,
        // QuoteService ni JobService (dependen solo del contrato).
        //
        // Nota: las pruebas que mockean estos contratos inyectan su propio
        // doble con $this->app->instance(...), por lo que reemplazar aquí el
        // binding por defecto es seguro.
        $this->app->bind(QuoteRequestNotifier::class, NotifyingQuoteRequestNotifier::class);
        $this->app->bind(QuoteNotifier::class, NotifyingQuoteNotifier::class);
        $this->app->bind(JobNotifier::class, NotifyingJobNotifier::class);

        // Seam de efectos al completar un Trabajo (Requisitos 7.4, 7.5).
        //
        // COORDINACIÓN tarea 9.2 / tareas 9.6 y 12.1: se resuelve a una
        // COMPOSICIÓN de handlers (CompositeJobCompletionHandler) para que cada
        // efecto de finalización coexista sin pisar a los demás:
        //   - tarea 9.6: ComplianceJobCompletionHandler recalcula y cachea el
        //     Índice_Cumplimiento del Prestador (Requisitos 9.1, 9.3, 9.5, 9.6).
        //   - tarea 12.1: RatingsJobCompletionHandler habilita las Calificaciones
        //     del Trabajo y fija la ventana de 30 días (Requisitos 7.4, 8.1),
        //     añadido como un elemento más de esta composición, sin tocar
        //     JobService, AutoCompleteJobJob ni el handler de cumplimiento.
        //
        // El seam se registra como SINGLETON para romper la dependencia circular
        // JobCompletionHandler → ComplianceJobCompletionHandler → JobService →
        // JobCompletionHandler: cada handler recibe una factoría perezosa del
        // servicio que necesita (JobService / RatingService) que solo se resuelve
        // dentro de onCompleted(), cuando el handler ya está construido y cacheado.
        $this->app->singleton(JobCompletionHandler::class, function ($app): CompositeJobCompletionHandler {
            return new CompositeJobCompletionHandler(
                new ComplianceJobCompletionHandler(fn (): JobService => $app->make(JobService::class)),
                new RatingsJobCompletionHandler(fn (): RatingService => $app->make(RatingService::class)),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Solo el Prestador del Trabajo gestiona sus Fotos_Trabajo (DD15).
        Gate::define('manage-job-photos', fn (User $user, Job $job): bool => (int) $job->provider_user_id === (int) $user->getKey());

        $this->logFailedMediaConversions();
    }

    /**
     * Log failed Spatie conversion jobs so they can be retried (Requisito 9.6).
     */
    protected function logFailedMediaConversions(): void
    {
        Event::listen(function (JobFailed $event): void {
            $payload = $event->job->payload();
            $command = $payload['data']['commandName'] ?? $payload['displayName'] ?? null;

            if ($command !== PerformConversionsJob::class) {
                return;
            }

            Log::error('Media conversion job failed.', [
                'job' => $command,
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $event->job->getJobId(),
                'exception' => $event->exception->getMessage(),
            ]);
        });
    }

    /**
     * Configura los rate limiters nombrados de la aplicación.
     *
     * Limiter `login` (Requisito 1.7): 5 intentos por IP en una ventana de
     * 15 minutos; al superarse, la IP queda bloqueada 15 minutos. El
     * controlador de login (tarea 2.3) solo debe contar los intentos
     * FALLIDOS mediante App\Support\LoginRateLimiter (hit/clear), por lo que
     * las credenciales válidas no consumen el límite.
     *
     * La respuesta de bloqueo es HTTP 429 con header `Retry-After` y un
     * mensaje que indica el tiempo restante, usando el envelope estándar
     * `{ data, message, errors? }`.
     *
     * El contador se respalda en el store de caché configurado (Redis en
     * producción). La configuración vive aquí, independiente del código de
     * negocio de AuthService, según la decisión técnica 7 del diseño.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinutes(
                decayMinutes: (int) (LoginRateLimiter::DECAY_SECONDS / 60),
                maxAttempts: LoginRateLimiter::MAX_ATTEMPTS,
            )
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    $retryAfter = (int) ($headers['Retry-After'] ?? LoginRateLimiter::availableIn($request));

                    return LoginRateLimiter::lockoutResponse($retryAfter);
                });
        });

        // Limiters del Muro de portafolios (Requisitos 8.6 a 8.8): ventana fija
        // de 60 s por usuario autenticado.
        foreach ([
            'wall-read' => 60,
            'wall-interactions' => 30,
            'wall-events' => 120,
            'wall-privacy' => 10,
            'job-photos' => 30,
        ] as $name => $maxAttempts) {
            RateLimiter::for($name, function (Request $request) use ($maxAttempts) {
                return Limit::perMinute($maxAttempts)
                    ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))
                    ->response(function (Request $request, array $headers) {
                        $retryAfter = max(1, min(60, (int) ($headers['Retry-After'] ?? 60)));

                        return ApiResponse::error(
                            message: sprintf(
                                'Demasiadas solicitudes. Intenta nuevamente en %d %s.',
                                $retryAfter,
                                $retryAfter === 1 ? 'segundo' : 'segundos',
                            ),
                            errors: ['retry_after' => [$retryAfter]],
                            status: 429,
                            meta: ['retry_after' => $retryAfter],
                        )->header('Retry-After', (string) $retryAfter);
                    });
            });
        }
    }
}

<?php

namespace App\Services;

use App\Enums\FeedEventType;
use App\Enums\FeedSubjectType;
use App\Jobs\RecordFeedEventsJob;
use App\Models\FeedPreference;
use App\Models\Job;
use App\Models\Portfolio;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Despacha el registro de eventos del Muro a la cola. Nunca lanza: un fallo aquí
 * no debe hacer fallar la acción del usuario (Requisito 5.11).
 */
class FeedEventRecorder
{
    public function record(User $user, Portfolio|Job $subject, FeedEventType $type): void
    {
        $subjectType = $subject instanceof Portfolio ? FeedSubjectType::Portfolio : FeedSubjectType::Job;

        $this->recordMany($user, [[
            'subject_type' => $subjectType,
            'subject_id' => $subject->id,
            'type' => $type,
            'occurred_at' => now(),
        ]]);
    }

    /**
     * Cada evento lleva `subject_type` (portfolio|job) y `subject_id`. Un evento sin
     * esas claves o con otro tipo de sujeto se descarta con un aviso en el log.
     *
     * @param  list<array{subject_type?:string|FeedSubjectType,subject_id?:int|string,type:FeedEventType,occurred_at:CarbonInterface}>  $events
     */
    public function recordMany(User $user, array $events): void
    {
        try {
            if ($events === [] || ! $this->isLoggingEnabled($user)) {
                return;
            }

            $payload = [];

            foreach ($events as $event) {
                $rawType = $event['subject_type'] ?? null;
                $subjectType = $rawType instanceof FeedSubjectType ? $rawType : FeedSubjectType::tryFrom((string) $rawType);

                if ($subjectType === null || ! isset($event['subject_id'])) {
                    Log::warning('Evento del Muro descartado: sujeto no admitido.', [
                        'user_id' => $user->id,
                        'subject_type' => is_scalar($rawType) ? $rawType : null,
                    ]);

                    continue;
                }

                $payload[] = [
                    'subject_type' => $subjectType->value,
                    'subject_id' => (int) $event['subject_id'],
                    'type' => $event['type']->value,
                    'occurred_at' => $event['occurred_at']->toIso8601String(),
                ];
            }

            if ($payload === []) {
                return;
            }

            RecordFeedEventsJob::dispatch($user->id, $payload)->afterCommit();
        } catch (Throwable $e) {
            Log::error('No se pudo despachar el registro de eventos del Muro.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function isLoggingEnabled(User $user): bool
    {
        return FeedPreference::query()
            ->where('user_id', $user->id)
            ->where('behavior_logging', true)
            ->exists();
    }
}

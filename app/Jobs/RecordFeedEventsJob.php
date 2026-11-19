<?php

namespace App\Jobs;

use App\Enums\FeedEventType;
use App\Models\FeedPreference;
use App\Models\Job;
use App\Models\Portfolio;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persiste en bloque eventos del Muro (Requisitos 9.x, 10.1, 10.3, 10.7, 10.11, 10.12).
 *
 * No almacena IP, agente de usuario ni coordenadas: solo usuario, sujeto, categoría,
 * tipo y fecha del evento. Cada fila escribe una sola columna FK según el sujeto.
 */
class RecordFeedEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * Columna FK de `feed_events` por tipo de sujeto.
     *
     * @var array<string, string>
     */
    private const FK_COLUMNS = [
        'portfolio' => 'portfolio_id',
        'job' => 'job_id',
    ];

    /**
     * Cada evento es `{subject_type, subject_id, type, occurred_at}`. Un evento con el
     * formato antiguo (`portfolio_item_id`) o con un sujeto no admitido se descarta con
     * `Log::warning`, sin error (DD7).
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $events,
    ) {}

    public function backoff(): int
    {
        return 10;
    }

    public function handle(): void
    {
        $enabled = FeedPreference::query()
            ->where('user_id', $this->userId)
            ->where('behavior_logging', true)
            ->exists();

        $events = $this->normalizedEvents();

        if (! $enabled || $events === []) {
            return;
        }

        $categories = $this->resolveCategories($events);

        $rows = [];
        $viewsInBatch = [];
        /** @var array<string, Lock> $locks */
        $locks = [];

        // El lock por (usuario, tipo de sujeto, id) se mantiene hasta la inserción
        // para que dos jobs concurrentes no escriban la misma vista (Req. 10.7).
        try {
            foreach ($events as $event) {
                $key = $event['subject_type'].':'.$event['subject_id'];

                if (! array_key_exists($key, $categories)) {
                    continue;
                }

                $type = FeedEventType::tryFrom($event['type']);

                if ($type === null) {
                    continue;
                }

                $occurredAt = CarbonImmutable::parse($event['occurred_at']);

                if ($type === FeedEventType::View) {
                    if (! isset($locks[$key])) {
                        $locks[$key] = Cache::lock("feed:view:{$this->userId}:{$key}", 10);
                        $locks[$key]->block(5);
                    }

                    if ($this->isDuplicateView($event['subject_type'], $event['subject_id'], $occurredAt, $viewsInBatch[$key] ?? [])) {
                        continue;
                    }

                    $viewsInBatch[$key][] = $occurredAt;
                }

                $rows[] = [
                    'user_id' => $this->userId,
                    'subject_type' => $event['subject_type'],
                    'portfolio_id' => null,
                    'job_id' => null,
                    self::FK_COLUMNS[$event['subject_type']] => $event['subject_id'],
                    'service_category_id' => $categories[$key],
                    'type' => $type->value,
                    'created_at' => $occurredAt,
                ];
            }

            if ($rows !== []) {
                DB::table('feed_events')->insert($rows);
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RecordFeedEventsJob descartado tras agotar los intentos.', [
            'user_id' => $this->userId,
            'events' => count($this->events),
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * @return list<array{subject_type:string,subject_id:int,type:string,occurred_at:string}>
     */
    private function normalizedEvents(): array
    {
        $normalized = [];
        $discarded = 0;

        foreach ($this->events as $event) {
            $subjectType = isset($event['subject_type']) && is_string($event['subject_type'])
                ? $event['subject_type']
                : null;

            if ($subjectType === null || ! isset(self::FK_COLUMNS[$subjectType], $event['subject_id'], $event['type'], $event['occurred_at'])) {
                $discarded++;

                continue;
            }

            $normalized[] = [
                'subject_type' => $subjectType,
                'subject_id' => (int) $event['subject_id'],
                'type' => (string) $event['type'],
                'occurred_at' => (string) $event['occurred_at'],
            ];
        }

        if ($discarded > 0) {
            Log::warning('RecordFeedEventsJob descartó eventos con formato o sujeto no admitido.', [
                'user_id' => $this->userId,
                'discarded' => $discarded,
            ]);
        }

        return $normalized;
    }

    /**
     * Devuelve la categoría (posiblemente nula) de cada sujeto existente, con clave
     * `{subject_type}:{subject_id}`. Los sujetos inexistentes no aparecen.
     *
     * @param  list<array{subject_type:string,subject_id:int,type:string,occurred_at:string}>  $events
     * @return array<string, int|null>
     */
    private function resolveCategories(array $events): array
    {
        $idsByType = [];

        foreach ($events as $event) {
            $idsByType[$event['subject_type']][$event['subject_id']] = true;
        }

        $categories = [];

        foreach ($idsByType as $subjectType => $ids) {
            $ids = array_keys($ids);

            $rows = $subjectType === 'portfolio'
                ? Portfolio::query()->whereIn('id', $ids)->pluck('service_category_id', 'id')
                : Job::query()->whereIn('id', $ids)->pluck('service_category_id', 'id');

            foreach ($rows as $id => $categoryId) {
                $categories["{$subjectType}:{$id}"] = $categoryId === null ? null : (int) $categoryId;
            }
        }

        return $categories;
    }

    /**
     * @param  list<CarbonImmutable>  $seenInBatch
     */
    private function isDuplicateView(string $subjectType, int $subjectId, CarbonImmutable $occurredAt, array $seenInBatch): bool
    {
        $windowStart = $occurredAt->subHours(24);

        foreach ($seenInBatch as $seen) {
            if ($seen->greaterThanOrEqualTo($windowStart)) {
                return true;
            }
        }

        return DB::table('feed_events')
            ->where('user_id', $this->userId)
            ->where(self::FK_COLUMNS[$subjectType], $subjectId)
            ->where('type', FeedEventType::View->value)
            ->where('created_at', '>=', $windowStart)
            ->exists();
    }
}

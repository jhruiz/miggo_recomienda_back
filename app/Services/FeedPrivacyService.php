<?php

namespace App\Services;

use App\Enums\FeedEventType;
use App\Enums\FeedSubjectType;
use App\Exceptions\InvalidWallCursorException;
use App\Models\FeedEvent;
use App\Models\FeedPreference;
use App\Models\User;
use App\Support\FeedEventPage;
use App\Support\PrivacySummary;
use App\Support\WallCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Privacidad del registro de comportamiento del Muro (Req. 6).
 *
 * Todas las operaciones se acotan al usuario recibido; nunca se acepta un id ajeno.
 */
class FeedPrivacyService
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function summary(User $user): PrivacySummary
    {
        $preference = FeedPreference::query()->where('user_id', $user->id)->first();

        $counts = FeedEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('subject_type', self::subjectTypes())
            ->selectRaw('type, COUNT(*) as total, MIN(created_at) as oldest')
            ->groupBy('type')
            ->get();

        $byType = [];
        foreach (FeedEventType::cases() as $type) {
            $byType[$type->value] = 0;
        }

        $bySubjectType = [];
        foreach (FeedSubjectType::cases() as $subjectType) {
            $bySubjectType[$subjectType->value] = 0;
        }

        $subjectCounts = FeedEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('subject_type', self::subjectTypes())
            ->selectRaw('subject_type, COUNT(*) as total')
            ->groupBy('subject_type')
            ->get();

        foreach ($subjectCounts as $row) {
            $key = $row->subject_type instanceof FeedSubjectType ? $row->subject_type->value : (string) $row->subject_type;
            $bySubjectType[$key] = (int) $row->total;
        }

        $oldest = null;
        foreach ($counts as $row) {
            $key = $row->type instanceof FeedEventType ? $row->type->value : (string) $row->type;
            $byType[$key] = (int) $row->total;
            $rowOldest = CarbonImmutable::parse($row->oldest);
            if ($oldest === null || $rowOldest->lt($oldest)) {
                $oldest = $rowOldest;
            }
        }

        $status = match (true) {
            $preference === null || $preference->privacy_notice_answered_at === null => PrivacySummary::STATUS_UNANSWERED,
            $preference->behavior_logging => PrivacySummary::STATUS_ENABLED,
            default => PrivacySummary::STATUS_DISABLED,
        };

        return new PrivacySummary(
            status: $status,
            behaviorLogging: (bool) ($preference?->behavior_logging ?? false),
            consentedAt: $preference?->consented_at,
            eventsTotal: array_sum($byType),
            eventsByType: $byType,
            eventsBySubjectType: $bySubjectType,
            oldestEventAt: $oldest,
        );
    }

    public function setBehaviorLogging(User $user, bool $enabled): PrivacySummary
    {
        $current = FeedPreference::query()->where('user_id', $user->id)->first();

        $values = ['behavior_logging' => $enabled];

        if ($current?->privacy_notice_answered_at === null) {
            $values['privacy_notice_answered_at'] = now();
        }

        if ($enabled && ! ($current?->behavior_logging ?? false)) {
            $values['consented_at'] = now();
        }

        FeedPreference::query()->updateOrCreate(['user_id' => $user->id], $values);

        return $this->summary($user);
    }

    /**
     * Historial del usuario, del más reciente al más antiguo.
     *
     * @throws InvalidWallCursorException
     */
    public function history(User $user, ?string $cursor, int $perPage = self::DEFAULT_PER_PAGE): FeedEventPage
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $decoded = $cursor === null ? null : WallCursor::decode($cursor, WallCursor::KIND_EVENTS);

        $events = FeedEvent::query()
            ->where('feed_events.user_id', $user->id)
            ->whereIn('feed_events.subject_type', self::subjectTypes())
            ->with([
                'portfolio.serviceCategory',
                'portfolio.items.media',
                'job.serviceCategory',
                'job.photos.media',
            ])
            ->when($decoded !== null, fn (Builder $query) => $query->where(
                fn (Builder $window) => $window
                    ->where('feed_events.created_at', '<', $decoded->sortedAt)
                    ->orWhere(fn (Builder $tie) => $tie
                        ->where('feed_events.created_at', $decoded->sortedAt)
                        ->where('feed_events.id', '<', $decoded->id)),
            ))
            ->orderByDesc('feed_events.created_at')
            ->orderByDesc('feed_events.id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $events->count() > $perPage;
        $page = $events->take($perPage)->values();
        $last = $page->last();

        $next = $hasMore && $last !== null
            ? (new WallCursor(WallCursor::KIND_EVENTS, CarbonImmutable::instance($last->created_at), $last->id))->encode()
            : null;

        return new FeedEventPage($page, $next);
    }

    /**
     * Tipos de sujeto vigentes. Las filas con otro sujeto (eventos de fotos sueltas que
     * aún existan hasta la migración de retiro) no se leen.
     *
     * @return list<string>
     */
    private static function subjectTypes(): array
    {
        return array_map(static fn (FeedSubjectType $type): string => $type->value, FeedSubjectType::cases());
    }

    /**
     * Borra todo el historial del usuario. Devuelve las filas eliminadas.
     */
    public function reset(User $user): int
    {
        return DB::transaction(
            fn (): int => DB::table('feed_events')->where('user_id', $user->id)->delete(),
        );
    }
}

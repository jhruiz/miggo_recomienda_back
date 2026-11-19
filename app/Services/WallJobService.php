<?php

namespace App\Services;

use App\Exceptions\InvalidWallCursorException;
use App\Exceptions\WallItemNotAvailableException;
use App\Models\City;
use App\Models\Job;
use App\Models\JobSave;
use App\Models\User;
use App\Support\KeysetWindow;
use App\Support\WallCursor;
use App\Support\WallPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas del Muro de Trabajos: feed, guardados, detalle y filtros.
 */
class WallJobService
{
    public const PAGE_SIZE = 12;

    public const NOT_AVAILABLE_MESSAGE = 'El trabajo no existe o no está disponible.';

    /**
     * @param  array{category_id?: int|null, city?: string|null}  $filters
     *
     * @throws InvalidWallCursorException
     */
    public function jobs(User $user, array $filters, ?string $cursor, int $perPage = self::PAGE_SIZE): WallPage
    {
        $decoded = $cursor === null ? null : WallCursor::decode($cursor, WallCursor::KIND_JOBS);
        $categoryId = $filters['category_id'] ?? null;
        $city = $filters['city'] ?? null;

        $query = $this->jobsQuery($user)
            ->when($categoryId !== null, fn (Builder $q) => $q->where('service_jobs.service_category_id', $categoryId))
            ->when($city !== null, fn (Builder $q) => $q->whereHas(
                'providerProfile',
                fn (Builder $profile) => $profile->whereIn(
                    'city_id',
                    City::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($city)])->select('id'),
                ),
            ));

        $rows = KeysetWindow::apply($query, 'service_jobs.completed_at', 'service_jobs.id', $decoded, $perPage)->get();
        [$page, $next] = KeysetWindow::slice($rows, $perPage, WallCursor::KIND_JOBS, 'completed_at');

        $this->decorate($page, $user);

        return new WallPage($page, $next);
    }

    /**
     * Alias de jobs() con el nombre usado por el diseño.
     *
     * @param  array{category_id?: int|null, city?: string|null}  $filters
     *
     * @throws InvalidWallCursorException
     */
    public function feed(User $user, array $filters, ?string $cursor, int $perPage = self::PAGE_SIZE): WallPage
    {
        return $this->jobs($user, $filters, $cursor, $perPage);
    }

    /**
     * Detalle de un Trabajo elegible con las mismas relaciones que la tarjeta.
     *
     * @throws WallItemNotAvailableException
     */
    public function detail(User $user, int $jobId): Job
    {
        $job = $this->jobsQuery($user)->whereKey($jobId)->first();

        if ($job === null) {
            throw new WallItemNotAvailableException(self::NOT_AVAILABLE_MESSAGE);
        }

        $this->decorate(collect([$job]), $user);

        return $job;
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function find(User $user, int $jobId): Job
    {
        return $this->detail($user, $jobId);
    }

    /**
     * Trabajo elegible sin relaciones (para interacciones).
     *
     * @throws WallItemNotAvailableException
     */
    public function findVisible(int $jobId): Job
    {
        $job = Job::query()->visibleOnWall()->whereKey($jobId)->first();

        if ($job === null) {
            throw new WallItemNotAvailableException(self::NOT_AVAILABLE_MESSAGE);
        }

        return $job;
    }

    /**
     * Trabajos elegibles del usuario como Cliente con su calificación aún abierta (una consulta).
     *
     * @return array{count: int, job_ids: list<int>}
     */
    public function pendingRating(User $user): array
    {
        $ids = Job::query()
            ->visibleOnWall()
            ->where('service_jobs.client_user_id', $user->id)
            ->whereHas('ratings', fn (Builder $rating) => $rating
                ->where('rater_user_id', $user->id)
                ->openForSubmission())
            ->orderByDesc('service_jobs.completed_at')
            ->orderByDesc('service_jobs.id')
            ->pluck('service_jobs.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return ['count' => count($ids), 'job_ids' => $ids];
    }

    /**
     * @throws InvalidWallCursorException
     */
    public function saved(User $user, ?string $cursor, int $perPage = self::PAGE_SIZE): WallPage
    {
        $decoded = $cursor === null ? null : WallCursor::decode($cursor, WallCursor::KIND_SAVED_JOBS);

        $query = JobSave::query()
            ->where('job_saves.user_id', $user->id)
            ->whereHas('job', fn (Builder $job) => $job->visibleOnWall())
            ->with(['job' => fn (Relation $job) => $job
                ->select('service_jobs.*')
                ->with($this->relations())
                ->withExists($this->interactionExists($user)),
            ]);

        $rows = KeysetWindow::apply($query, 'job_saves.created_at', 'job_saves.id', $decoded, $perPage)->get();
        [$page, $next] = KeysetWindow::slice($rows, $perPage, WallCursor::KIND_SAVED_JOBS, 'created_at');

        $jobs = $page->map(fn (JobSave $save) => $save->job)->values();
        $this->decorate($jobs, $user);

        return new WallPage($jobs, $next);
    }

    /**
     * Categorías y ciudades con el conteo de Trabajos elegibles (sin Trabajos sin categoría).
     *
     * @return array{categories: list<array{id: int, name: string, slug: string, items_count: int}>, cities: list<array{name: string, items_count: int}>}
     */
    public function filters(): array
    {
        $categories = Job::query()
            ->visibleOnWall()
            ->join('service_categories', 'service_categories.id', '=', 'service_jobs.service_category_id')
            ->groupBy('service_categories.id', 'service_categories.name', 'service_categories.slug')
            ->orderBy('service_categories.name')
            ->selectRaw('service_categories.id as id, service_categories.name as name, service_categories.slug as slug, COUNT(*) as items_count')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'items_count' => (int) $row->items_count,
            ])
            ->all();

        $cities = Job::query()
            ->visibleOnWall()
            ->join('provider_profiles', 'provider_profiles.user_id', '=', 'service_jobs.provider_user_id')
            ->join('cities', 'cities.id', '=', 'provider_profiles.city_id')
            ->groupBy(DB::raw('LOWER(cities.name)'))
            ->orderBy(DB::raw('LOWER(cities.name)'))
            ->selectRaw('MIN(cities.name) as name, COUNT(*) as items_count')
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name,
                'items_count' => (int) $row->items_count,
            ])
            ->all();

        return ['categories' => $categories, 'cities' => $cities];
    }

    /**
     * @return Builder<Job>
     */
    private function jobsQuery(User $user): Builder
    {
        return Job::query()
            ->visibleOnWall()
            ->select('service_jobs.*')
            ->with($this->relations())
            ->withExists($this->interactionExists($user));
    }

    /**
     * Eager loads constantes (8 consultas por página con el listado).
     *
     * @return array<int|string, mixed>
     */
    private function relations(): array
    {
        return [
            'serviceCategory:id,name,slug',
            'providerProfile:id,user_id,city_id,compliance_index',
            'providerProfile.user:id,name',
            'providerProfile.city:id,name',
            'photos' => fn ($photos) => $photos->ready()->with('media'),
            'ratings:id,job_id,rater_user_id,score,comment,is_visible,rated_at,window_closes_at',
        ];
    }

    /**
     * @return array<string, \Closure>
     */
    private function interactionExists(User $user): array
    {
        return [
            'likes as liked_by_me' => fn ($query) => $query->where('user_id', $user->id),
            'saves as saved_by_me' => fn ($query) => $query->where('user_id', $user->id),
        ];
    }

    /**
     * Calcula can_rate_by_me con las calificaciones ya cargadas (sin consultas).
     *
     * @param  Collection<int, Job>  $jobs
     */
    private function decorate(Collection $jobs, User $user): void
    {
        foreach ($jobs as $job) {
            $own = (int) $job->client_user_id === (int) $user->id
                ? $job->ratings->first(fn ($rating) => (int) $rating->rater_user_id === (int) $user->id)
                : null;

            $job->setAttribute('can_rate_by_me', $own?->isOpenForSubmission() ?? false);
        }
    }
}

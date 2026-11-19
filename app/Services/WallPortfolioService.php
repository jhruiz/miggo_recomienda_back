<?php

namespace App\Services;

use App\Exceptions\InvalidWallCursorException;
use App\Exceptions\WallItemNotAvailableException;
use App\Models\City;
use App\Models\Portfolio;
use App\Models\PortfolioSave;
use App\Models\User;
use App\Support\KeysetWindow;
use App\Support\WallCursor;
use App\Support\WallPage;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas del Muro para la pestaña Portafolios: listado, guardados, detalle y filtros.
 */
final class WallPortfolioService
{
    public const PAGE_SIZE = 12;

    public const COVER_PHOTOS = 4;

    private const NOT_AVAILABLE_MESSAGE = 'El portafolio no existe o no está disponible.';

    /**
     * Relaciones constantes de cada tarjeta (las fotos se cargan aparte).
     *
     * @var list<string>
     */
    private const CARD_RELATIONS = [
        'serviceCategory',
        'providerProfile.user:id,name',
        'providerProfile.city:id,name',
    ];

    /**
     * @param  array{category_id?: int|null, city?: string|null}  $filters
     *
     * @throws InvalidWallCursorException
     */
    public function feed(User $user, array $filters, ?string $cursor, int $perPage = self::PAGE_SIZE): WallPage
    {
        $decoded = $cursor === null ? null : WallCursor::decode($cursor, WallCursor::KIND_PORTFOLIOS);
        $categoryId = $filters['category_id'] ?? null;
        $city = $filters['city'] ?? null;

        $query = Portfolio::query()
            ->visibleOnWall()
            ->when($categoryId !== null, fn (Builder $q) => $q->where('portfolios.service_category_id', $categoryId))
            ->when($city !== null, fn (Builder $q) => $q->whereHas(
                'providerProfile',
                fn (Builder $profile) => $profile->whereIn(
                    'city_id',
                    City::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($city)])->select('id'),
                ),
            ));

        $this->withCardData($query, $user);

        $rows = KeysetWindow::apply($query, 'portfolios.created_at', 'portfolios.id', $decoded, $perPage)->get();

        [$page, $next] = KeysetWindow::slice($rows, $perPage, WallCursor::KIND_PORTFOLIOS, 'created_at');

        return new WallPage($this->limitCoverPhotos($page), $next);
    }

    /**
     * Alias de feed() con el nombre usado por los controladores del Muro.
     *
     * @param  array{category_id?: int|null, city?: string|null}  $filters
     *
     * @throws InvalidWallCursorException
     */
    public function portfolios(User $user, array $filters, ?string $cursor, int $perPage = self::PAGE_SIZE): WallPage
    {
        return $this->feed($user, $filters, $cursor, $perPage);
    }

    /**
     * Portafolios guardados por el Usuario, por fecha de guardado descendente.
     *
     * @throws InvalidWallCursorException
     */
    public function saved(User $user, ?string $cursor, int $perPage = self::PAGE_SIZE): WallPage
    {
        $decoded = $cursor === null ? null : WallCursor::decode($cursor, WallCursor::KIND_SAVED_PORTFOLIOS);

        $query = PortfolioSave::query()
            ->where('portfolio_saves.user_id', $user->id)
            ->whereHas('portfolio', fn (Builder $portfolio) => $portfolio->visibleOnWall())
            ->with(['portfolio' => fn ($portfolio) => $this->withCardData($portfolio, $user)]);

        $saves = KeysetWindow::apply($query, 'portfolio_saves.created_at', 'portfolio_saves.id', $decoded, $perPage)->get();

        [$page, $next] = KeysetWindow::slice($saves, $perPage, WallCursor::KIND_SAVED_PORTFOLIOS, 'created_at');

        $portfolios = new Collection($page->map(fn (PortfolioSave $save) => $save->portfolio)->filter()->values()->all());

        return new WallPage($this->limitCoverPhotos($portfolios), $next);
    }

    /**
     * Detalle con todas las fotos listas del Portafolio.
     *
     * @throws WallItemNotAvailableException
     */
    public function detail(User $user, int $portfolioId): Portfolio
    {
        $query = Portfolio::query()
            ->visibleOnWall()
            ->whereKey($portfolioId)
            ->with([
                ...self::CARD_RELATIONS,
                'items' => fn ($items) => $items
                    ->whereNotNull('portfolio_items.conversions_ready_at')
                    ->with('media'),
            ])
            ->withCount(['items as ready_photos_count' => $this->readyPhotos()])
            ->withExists($this->interactionExists($user));

        $portfolio = $query->first();

        if ($portfolio === null) {
            throw new WallItemNotAvailableException(self::NOT_AVAILABLE_MESSAGE);
        }

        return $portfolio;
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function findVisible(int $portfolioId): Portfolio
    {
        $portfolio = Portfolio::query()->visibleOnWall()->whereKey($portfolioId)->first();

        if ($portfolio === null) {
            throw new WallItemNotAvailableException(self::NOT_AVAILABLE_MESSAGE);
        }

        return $portfolio;
    }

    /**
     * Categorías y ciudades con el conteo de Portafolios elegibles (sin Portafolio_Sin_Categoría en categorías).
     *
     * @return array{categories: list<array{id: int, name: string, slug: string, portfolios_count: int}>, cities: list<array{name: string, portfolios_count: int}>}
     */
    public function filters(): array
    {
        $categories = Portfolio::query()
            ->visibleOnWall()
            ->join('service_categories', 'service_categories.id', '=', 'portfolios.service_category_id')
            ->groupBy('service_categories.id', 'service_categories.name', 'service_categories.slug')
            ->orderBy('service_categories.name')
            ->selectRaw('service_categories.id as id, service_categories.name as name, service_categories.slug as slug, COUNT(*) as portfolios_count')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'portfolios_count' => (int) $row->portfolios_count,
            ])
            ->all();

        $cities = Portfolio::query()
            ->visibleOnWall()
            ->join('provider_profiles', 'provider_profiles.id', '=', 'portfolios.provider_profile_id')
            ->join('cities', 'cities.id', '=', 'provider_profiles.city_id')
            ->groupBy(DB::raw('LOWER(cities.name)'))
            ->orderBy(DB::raw('LOWER(cities.name)'))
            ->selectRaw('MIN(cities.name) as name, COUNT(*) as portfolios_count')
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name,
                'portfolios_count' => (int) $row->portfolios_count,
            ])
            ->all();

        return ['categories' => $categories, 'cities' => $cities];
    }

    /**
     * @param  Builder<Portfolio>|\Illuminate\Database\Eloquent\Relations\Relation<Portfolio, *, *>  $query
     */
    private function withCardData($query, User $user): void
    {
        $query
            ->with([
                ...self::CARD_RELATIONS,
                'items' => fn ($items) => $items
                    ->whereNotNull('portfolio_items.conversions_ready_at')
                    ->limit(self::COVER_PHOTOS)
                    ->with('media'),
            ])
            ->withCount(['items as ready_photos_count' => $this->readyPhotos()])
            ->withExists($this->interactionExists($user));
    }

    /**
     * Respaldo en PHP por si el límite por padre del eager load no se aplica.
     *
     * @param  \Illuminate\Support\Collection<int, Portfolio>  $portfolios
     * @return Collection<int, Portfolio>
     */
    private function limitCoverPhotos(\Illuminate\Support\Collection $portfolios): Collection
    {
        foreach ($portfolios as $portfolio) {
            $portfolio->setRelation('items', $portfolio->items->take(self::COVER_PHOTOS)->values());
        }

        return new Collection($portfolios->values()->all());
    }

    private function readyPhotos(): Closure
    {
        return fn ($items) => $items->whereNotNull('portfolio_items.conversions_ready_at');
    }

    /**
     * @return array<string, Closure>
     */
    private function interactionExists(User $user): array
    {
        return [
            'likes as liked_by_me' => fn ($query) => $query->where('user_id', $user->id),
            'saves as saved_by_me' => fn ($query) => $query->where('user_id', $user->id),
        ];
    }
}

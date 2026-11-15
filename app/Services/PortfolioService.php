<?php

namespace App\Services;

use App\Exceptions\PortfolioCountLimitReachedException;
use App\Exceptions\PortfolioPhotoLimitReachedException;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\ProviderProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

/**
 * Servicio de Portafolios múltiples por Prestador.
 */
final class PortfolioService
{
    public const MAX_PORTFOLIOS_PER_PROVIDER = 20;

    public const MAX_ITEMS_PER_PORTFOLIO = 10;

    public const MEDIA_COLLECTION = 'portfolio';

    private const DUPLICATE_NAME_MESSAGE = 'Ya tienes un portafolio con ese nombre.';

    /**
     * @return Collection<int, Portfolio>
     */
    public function listForOwner(ProviderProfile $profile): Collection
    {
        return $profile->portfolios()
            ->withCount('items')
            ->with(['items.media'])
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PortfolioCountLimitReachedException
     */
    public function create(ProviderProfile $profile, array $data): Portfolio
    {
        try {
            return DB::transaction(function () use ($profile, $data): Portfolio {
                ProviderProfile::whereKey($profile->getKey())->lockForUpdate()->first();

                if ($profile->portfolios()->count() >= self::MAX_PORTFOLIOS_PER_PROVIDER) {
                    throw new PortfolioCountLimitReachedException(self::MAX_PORTFOLIOS_PER_PROVIDER);
                }

                /** @var Portfolio $portfolio */
                $portfolio = $profile->portfolios()->create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                ]);

                return $portfolio->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Portfolio $portfolio, array $data): Portfolio
    {
        try {
            $portfolio->fill(array_intersect_key($data, array_flip(['name', 'description'])));
            $portfolio->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
        }

        return $portfolio->refresh();
    }

    /**
     * Borra cada foto vía Eloquent (para que Media Library elimine archivos y
     * conversiones) y luego el Portafolio. Retorna el número de fotos borradas.
     */
    public function delete(Portfolio $portfolio): int
    {
        return DB::transaction(function () use ($portfolio): int {
            $deleted = 0;

            $portfolio->items()->get()->each(function (PortfolioItem $item) use (&$deleted): void {
                $item->delete();
                $deleted++;
            });

            $portfolio->delete();

            return $deleted;
        });
    }

    /**
     * @throws PortfolioPhotoLimitReachedException
     * @throws ValidationException
     */
    public function addItem(Portfolio $portfolio, UploadedFile $image, ?string $description = null): PortfolioItem
    {
        try {
            return DB::transaction(function () use ($portfolio, $image, $description): PortfolioItem {
                Portfolio::whereKey($portfolio->getKey())->lockForUpdate()->first();

                if ($portfolio->items()->count() >= self::MAX_ITEMS_PER_PORTFOLIO) {
                    throw new PortfolioPhotoLimitReachedException(self::MAX_ITEMS_PER_PORTFOLIO);
                }

                /** @var PortfolioItem $item */
                $item = $portfolio->items()->create(['description' => $description]);

                $item->addMedia($image)->toMediaCollection(self::MEDIA_COLLECTION);

                return $item->refresh();
            });
        } catch (FileUnacceptableForCollection|FileIsTooBig $e) {
            // La transacción ya hizo rollback al propagarse la excepción.
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(PortfolioItem $item, array $data): PortfolioItem
    {
        $item->fill(array_intersect_key($data, array_flip(['description'])));
        $item->save();

        return $item->refresh();
    }

    public function deleteItem(PortfolioItem $item): void
    {
        $item->delete();
    }

    /**
     * Representación para el dueño (incluye Portafolios vacíos).
     *
     * @return array<string, mixed>
     */
    public function presentForOwner(Portfolio $portfolio): array
    {
        $portfolio->loadMissing('items.media');

        $items = $portfolio->items->sortBy('id')->values();

        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'description' => $portfolio->description,
            'photos_count' => $items->count(),
            'cover_thumb_url' => $this->coverThumbUrl($items),
            'created_at' => $portfolio->created_at?->toIso8601String(),
            'items' => $items->map(fn (PortfolioItem $item): array => $this->presentItem($item))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentItem(PortfolioItem $item): array
    {
        $item->loadMissing('media');

        $media = $item->getFirstMedia(self::MEDIA_COLLECTION);

        return [
            'id' => $item->id,
            'portfolio_id' => $item->portfolio_id,
            'description' => $item->description,
            'image_url' => $media?->getUrl(),
            'thumb_url' => $media?->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    /**
     * Solo Portafolios con al menos una foto, ordenados por created_at, id.
     *
     * @return list<array<string, mixed>>
     */
    public function presentPublic(ProviderProfile $profile): array
    {
        $portfolios = $profile->portfolios()
            ->whereHas('items')
            ->with(['items.media'])
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $portfolios->map(function (Portfolio $portfolio): array {
            $items = $portfolio->items->sortBy('id')->values();

            return [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'description' => $portfolio->description,
                'cover_thumb_url' => $this->coverThumbUrl($items),
                'items' => $items->map(function (PortfolioItem $item): array {
                    $presented = $this->presentItem($item);

                    return [
                        'id' => $presented['id'],
                        'description' => $presented['description'],
                        'image_url' => $presented['image_url'],
                        'thumb_url' => $presented['thumb_url'],
                    ];
                })->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, PortfolioItem>  $items  ordenados por id
     */
    private function coverThumbUrl(Collection $items): ?string
    {
        $first = $items->first();

        return $first === null ? null : $this->presentItem($first)['thumb_url'];
    }
}

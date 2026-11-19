<?php

namespace App\Services;

use App\Exceptions\PortfolioCountLimitReachedException;
use App\Exceptions\PortfolioPhotoLimitReachedException;
use App\Http\Resources\PortfolioItemResource;
use App\Http\Resources\PortfolioResource;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\ProviderProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Throwable;

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
            ->with(['items.media', 'serviceCategory'])
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
                    'service_category_id' => $data['service_category_id'],
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
            $portfolio->fill(array_intersect_key($data, array_flip(['name', 'description', 'service_category_id'])));
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

    /**
     * Borra la foto con sus archivos. Media Library elimina el registro Media y
     * luego sus archivos dentro del evento `deleting` de la foto; la transacción
     * garantiza que, si falla el borrado de archivos, tanto la foto como su
     * Media se conserven y el borrado pueda reintentarse.
     *
     * @throws Throwable
     */
    public function deleteItem(PortfolioItem $item): void
    {
        try {
            DB::transaction(fn (): ?bool => $item->delete());
        } catch (Throwable $e) {
            Log::error('No se pudo borrar la foto del portafolio.', [
                'portfolio_item_id' => $item->getKey(),
                'portfolio_id' => $item->portfolio_id,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Representación para el dueño (incluye Portafolios vacíos).
     *
     * @return array<string, mixed>
     */
    public function presentForOwner(Portfolio $portfolio): array
    {
        return (new PortfolioResource($portfolio))->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentItem(PortfolioItem $item): array
    {
        return (new PortfolioItemResource($item))->resolve();
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
            ->with(['items.media', 'serviceCategory'])
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $portfolios
            ->map(fn (Portfolio $portfolio): array => (new PortfolioResource($portfolio, true))->resolve())
            ->values()
            ->all();
    }

    /**
     * Cantidad de Portafolios del Prestador sin categoría (aviso de pendientes).
     */
    public function countUncategorized(ProviderProfile $profile): int
    {
        return $profile->portfolios()->whereNull('service_category_id')->count();
    }
}

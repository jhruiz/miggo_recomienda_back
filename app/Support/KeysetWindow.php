<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ventana keyset (fecha DESC, id DESC) compartida por los listados del Muro.
 */
final class KeysetWindow
{
    /**
     * Añade WHERE (fecha < c.fecha OR (fecha = c.fecha AND id < c.id)),
     * ORDER BY fecha DESC, id DESC y LIMIT n+1.
     */
    public static function apply(
        Builder $query,
        string $dateColumn,
        string $idColumn,
        ?WallCursor $cursor,
        int $perPage,
    ): Builder {
        return $query
            ->when($cursor !== null, fn (Builder $q) => $q->where(
                fn (Builder $window) => $window
                    ->where($dateColumn, '<', $cursor->sortedAt)
                    ->orWhere(fn (Builder $tie) => $tie
                        ->where($dateColumn, $cursor->sortedAt)
                        ->where($idColumn, '<', $cursor->id)),
            ))
            ->orderByDesc($dateColumn)
            ->orderByDesc($idColumn)
            ->limit($perPage + 1);
    }

    /**
     * @param  Collection<int, mixed>  $rows  Filas obtenidas con apply() (hasta n+1).
     * @return array{0: Collection<int, mixed>, 1: ?string} Página recortada y cursor firmado (o null).
     */
    public static function slice(Collection $rows, int $perPage, string $kind, string $dateAttribute): array
    {
        $hasMore = $rows->count() > $perPage;
        $page = $rows->take($perPage)->values();
        $last = $page->last();

        if (! $hasMore || $last === null) {
            return [$page, null];
        }

        $date = $last->{$dateAttribute};
        $sortedAt = $date instanceof CarbonInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date);

        return [$page, (new WallCursor($kind, $sortedAt, $last->id))->encode()];
    }
}

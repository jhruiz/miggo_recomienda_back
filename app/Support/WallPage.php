<?php

namespace App\Support;

use App\Models\PortfolioItem;
use Illuminate\Support\Collection;

/**
 * Página del Muro o de Guardados: ítems más el cursor firmado de la página siguiente.
 */
final readonly class WallPage
{
    /**
     * @param  Collection<int, PortfolioItem>  $items
     */
    public function __construct(
        public Collection $items,
        public ?string $nextCursor,
    ) {}
}

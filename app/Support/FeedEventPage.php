<?php

namespace App\Support;

use App\Models\FeedEvent;
use Illuminate\Support\Collection;

/**
 * Página del historial de eventos: eventos más el cursor firmado de la siguiente.
 */
final readonly class FeedEventPage
{
    /**
     * @param  Collection<int, FeedEvent>  $events
     */
    public function __construct(
        public Collection $events,
        public ?string $nextCursor,
    ) {}
}

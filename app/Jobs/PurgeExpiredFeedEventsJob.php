<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Purga de retención de 12 meses de `feed_events` (tarea 8.2, Requisito 6.18).
 *
 * Se programa a diario en routes/console.php. Borra por lotes los eventos con
 * `created_at` anterior a `now()->subMonths(12)` hasta que no queden. Si falla,
 * la siguiente ejecución diaria retoma el trabajo.
 */
class PurgeExpiredFeedEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const BATCH_SIZE = 10000;

    public function __construct(public readonly int $batchSize = self::BATCH_SIZE) {}

    public function handle(): void
    {
        $cutoff = now()->subMonths(12);

        do {
            // Se seleccionan los ids del lote y luego se borran: DELETE ... LIMIT
            // no es portable entre motores (SQLite, MySQL).
            $ids = DB::table('feed_events')
                ->where('created_at', '<', $cutoff)
                ->limit($this->batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            DB::table('feed_events')->whereIn('id', $ids->all())->delete();
        } while (true);
    }
}

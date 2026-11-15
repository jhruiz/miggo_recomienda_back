<?php

namespace App\Jobs;

use App\Contracts\QuoteNotifier;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Expiración automática de una Cotización (tarea 8.8, Requisitos 6.2, 6.3, 6.6).
 *
 * Se despacha al crear una Cotización con un delay hasta su `expires_at`
 * (ver QuoteService::createQuote y la decisión técnica 1 del diseño: Jobs con
 * delay en lugar de comandos programados). Al ejecutarse:
 *
 *  - Recarga la Cotización desde la base de datos (el estado pudo cambiar
 *    mientras el Job esperaba en la cola).
 *  - Si está "pendiente", la pasa a "expirada" y notifica a AMBAS partes
 *    (Prestador y Cliente) a través del seam QuoteNotifier (Requisitos 6.2, 6.3).
 *  - Si NO está "pendiente" (p. ej. "aceptada", "rechazada" o ya "expirada"),
 *    no modifica nada: las Cotizaciones en estado terminal se respetan
 *    (Requisito 6.6).
 *
 * Se referencia la Cotización por id (no por instancia serializada) para que
 * el estado se lea siempre fresco en el momento de ejecución.
 */
class ExpireQuoteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $quoteId)
    {
    }

    /**
     * Marca la Cotización como "expirada" solo si sigue "pendiente" y notifica
     * a ambas partes; no toca Cotizaciones en estado terminal (Requisito 6.6).
     */
    public function handle(QuoteNotifier $notifier): void
    {
        /** @var Quote|null $quote */
        $quote = Quote::query()->find($this->quoteId);

        // La Cotización pudo eliminarse mientras el Job esperaba en la cola.
        if ($quote === null) {
            return;
        }

        // Solo "pendiente" transiciona a "expirada". Cualquier otro estado
        // (aceptada, rechazada, ya expirada, ...) se deja intacto (Req 6.6).
        if ($quote->status !== Quote::STATUS_PENDIENTE) {
            return;
        }

        // Guarda defensiva (Requisito 6.2): solo expira cuando la fecha de
        // expiración ya se ha alcanzado. Protege contra ejecuciones tempranas
        // del Job (p. ej. el driver `sync` ignora el delay, o un worker que
        // adelanta su ejecución): si aún no es la fecha, no se modifica nada.
        if ($quote->expires_at !== null && $quote->expires_at->isFuture()) {
            return;
        }

        $quote->status = Quote::STATUS_EXPIRADA;
        $quote->save();

        // Notifica el vencimiento al Prestador y al Cliente (Requisito 6.3).
        $notifier->notifyQuoteExpired($quote);
    }
}

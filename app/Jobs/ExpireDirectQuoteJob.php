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
 * Vencimiento del plazo de respuesta de una Cotización directa (tarea 11.1,
 * Requisito 10.3).
 *
 * Se despacha al crear la solicitud de Cotización directa con un delay de
 * 7 días calendario (ver QuoteService::requestDirectQuote y la decisión técnica
 * 1 del diseño: Jobs con delay en lugar de comandos programados). Sigue el
 * mismo patrón de guarda que ExpireQuoteJob. Al ejecutarse:
 *
 *  - Recarga la Cotización desde la base de datos (su estado pudo cambiar
 *    mientras el Job esperaba en la cola: el Prestador pudo responder).
 *  - Si sigue "solicitada", la pasa a "sin_respuesta" y notifica al Cliente
 *    solicitante a través del seam QuoteNotifier (Requisito 10.3).
 *  - Si NO está "solicitada" (p. ej. ya "pendiente" porque el Prestador
 *    respondió, o ya "sin_respuesta"), no modifica nada.
 *
 * Se referencia la Cotización por id (no por instancia serializada) para que
 * el estado se lea siempre fresco en el momento de ejecución.
 */
class ExpireDirectQuoteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $quoteId)
    {
    }

    /**
     * Marca la Cotización directa como "sin_respuesta" solo si sigue
     * "solicitada" y notifica al Cliente; no toca Cotizaciones en cualquier
     * otro estado (Requisito 10.3).
     */
    public function handle(QuoteNotifier $notifier): void
    {
        /** @var Quote|null $quote */
        $quote = Quote::query()->find($this->quoteId);

        // La Cotización pudo eliminarse mientras el Job esperaba en la cola.
        if ($quote === null) {
            return;
        }

        // Solo "solicitada" transiciona a "sin_respuesta". Cualquier otro estado
        // (el Prestador ya respondió -> "pendiente", o ya "sin_respuesta") se
        // deja intacto.
        if ($quote->status !== Quote::STATUS_SOLICITADA) {
            return;
        }

        // Guarda defensiva: solo vence cuando el plazo de 7 días ya se ha
        // alcanzado. Protege contra ejecuciones tempranas del Job (p. ej. el
        // driver `sync` ignora el delay). `expires_at` guarda el plazo de
        // respuesta de la solicitud directa (fecha de creación + 7 días).
        if ($quote->expires_at !== null && $quote->expires_at->isFuture()) {
            return;
        }

        $quote->status = Quote::STATUS_SIN_RESPUESTA;
        $quote->save();

        // Notifica al Cliente que su solicitud no fue respondida (Requisito 10.3).
        $notifier->notifyClientOfDirectNoResponse($quote);
    }
}

<?php

namespace App\Services;

use App\Events\NewChatMessage;
use App\Exceptions\ChatMessageException;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Job;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestApplication;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Servicio de Chat (Gestor_Chat del dominio).
 *
 * Cubre la tarea 13.1: creación de canales de Chat y transición de su estado a
 * lo largo del ciclo de vida de Trabajos y Solicitudes_Cotización
 * (Requisitos 11.1, 11.2, 11.3).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tarea 13.1 / tareas 9.1, 7.2)
 * -----------------------------------------------------------------------------
 * Este servicio encapsula ÚNICAMENTE la gestión del ciclo de vida del canal
 * (crear / cerrar). Se engancha de forma ADITIVA desde:
 *   - JobService::createJob()            → createActiveChannelForJob()   (Req 11.1)
 *   - QuoteRequestService::apply()       → createPreAcceptanceChannel()  (Req 11.2)
 *   - QuoteRequestService::close()       → closePreAcceptanceChannelsForRequest() (Req 11.3)
 *
 * Todas las operaciones son IDEMPOTENTES: no se duplica un canal si ya existe
 * uno para el Trabajo o la postulación, y cerrar un canal ya cerrado es un
 * no-op. Esto mantiene seguros los flujos existentes (createJob/apply/close se
 * invocan ampliamente) ante reintentos y transacciones.
 *
 * -----------------------------------------------------------------------------
 * TAREA 13.3: envío, persistencia e historial de mensajes
 * -----------------------------------------------------------------------------
 * De forma aditiva, este servicio también cubre el envío de mensajes
 * (sendMessage, Requisitos 11.4, 11.5, 11.7), su historial paginado por cursor
 * (messagesFor, Requisito 11.6) y el listado de canales del usuario
 * (channelsForUser). La validación de contenido 1-2000 caracteres (Requisito
 * 11.8) se realiza en el Form Request (HTTP 422) antes de llegar aquí; este
 * servicio asume contenido ya validado.
 *
 * -----------------------------------------------------------------------------
 * TAREA 13.6: notificación de mensaje al receptor (Requisito 11.9)
 * -----------------------------------------------------------------------------
 * De forma ADITIVA, sendMessage() invoca ahora al Gestor_Notificaciones
 * (NotificationService) para avisar al receptor —el OTRO participante del canal,
 * nunca el emisor— de que ha recibido un mensaje nuevo. Como el servidor no
 * puede saber de forma fiable si el receptor tiene el canal "abierto" en su
 * cliente, la notificación se emite de forma INCONDICIONAL en cada mensaje
 * (comportamiento best-effort y la opción correcta más simple frente al matiz
 * "si el receptor no tiene el canal abierto" del diseño). El cliente puede
 * suprimir visualmente la notificación cuando el canal esté enfocado.
 *
 * NotificationService::notify() persiste la fila in-app y despacha su envío
 * asíncrono por Reverb; no bloquea ni compromete la persistencia del mensaje.
 * -----------------------------------------------------------------------------
 */
class ChatService
{
    /**
     * Relaciones que describen el contexto de un canal (partes, Solicitud de
     * origen y Trabajo). Se cargan en lote para no hacer N+1 en el listado.
     */
    public const CONTEXT_RELATIONS = [
        'client:id,name',
        'provider:id,name',
        'client.media',
        'provider.media',
        'quoteRequestApplication:id,quote_request_id',
        'quoteRequestApplication.quoteRequest:id,title,status,service_category_id,city_id',
        'quoteRequestApplication.quoteRequest.category:id,name',
        'quoteRequestApplication.quoteRequest.city:id,name',
        'job:id,quote_id,status',
        'job.quote:id,quote_request_id,type',
        'job.quote.quoteRequest:id,title,status,service_category_id,city_id',
        'job.quote.quoteRequest.category:id,name',
        'job.quote.quoteRequest.city:id,name',
    ];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Habilita el canal de Chat "activo" asociado a un Trabajo recién creado
     * entre su Cliente y su Prestador (Requisito 11.1).
     *
     * Idempotente respecto a la relación 1:1 Trabajo→Canal: si el Trabajo ya
     * tiene un canal, lo devuelve en lugar de crear uno nuevo. Esto evita
     * duplicados ante reintentos o invocaciones repetidas de createJob().
     */
    public function createActiveChannelForJob(Job $job): ChatChannel
    {
        $existing = $job->chatChannel()->first();

        if ($existing !== null) {
            return $existing;
        }

        return ChatChannel::create([
            'job_id' => $job->id,
            'client_user_id' => $job->client_user_id,
            'provider_user_id' => $job->provider_user_id,
            'status' => ChatChannel::STATUS_ACTIVO,
        ]);
    }

    /**
     * Habilita el canal de Chat "pre_aceptacion" al postular un Prestador a una
     * Solicitud_Cotización, para consultas previas a la aceptación formal
     * (Requisito 11.2).
     *
     * El Cliente del canal es el dueño de la Solicitud y el Prestador es quien
     * postula; el canal se enlaza a la postulación vía
     * `quote_request_application_id`.
     *
     * Idempotente respecto a la relación 1:1 Postulación→Canal: si la
     * postulación ya tiene un canal, lo devuelve sin crear uno nuevo.
     */
    public function createPreAcceptanceChannel(QuoteRequestApplication $application): ChatChannel
    {
        $existing = $application->chatChannel()->first();

        if ($existing !== null) {
            return $existing;
        }

        $clientUserId = $application->quoteRequest()->value('client_user_id');

        return ChatChannel::create([
            'quote_request_application_id' => $application->id,
            'client_user_id' => $clientUserId,
            'provider_user_id' => $application->provider_user_id,
            'status' => ChatChannel::STATUS_PRE_ACEPTACION,
        ]);
    }

    /**
     * Cierra los canales de Chat en estado "pre_aceptacion" asociados a las
     * postulaciones de una Solicitud_Cotización que se cierra sin aceptación
     * (Requisito 11.3).
     *
     * Solo afecta a los canales en estado "pre_aceptacion": pasa su estado a
     * "cerrado" para impedir el envío de nuevos mensajes. Los canales ya
     * cerrados o de otro estado no se tocan (idempotente). Devuelve el número
     * de canales cerrados.
     */
    public function closePreAcceptanceChannelsForRequest(QuoteRequest $quoteRequest): int
    {
        $applicationIds = $quoteRequest->applications()->pluck('id');

        if ($applicationIds->isEmpty()) {
            return 0;
        }

        return ChatChannel::query()
            ->whereIn('quote_request_application_id', $applicationIds)
            ->where('status', ChatChannel::STATUS_PRE_ACEPTACION)
            ->update(['status' => ChatChannel::STATUS_CERRADO]);
    }

    /**
     * Cierra el canal de Chat "pre_aceptacion" asociado a una postulación
     * concreta que es rechazada (Requisito 11.3). No-op si no existe canal o si
     * su estado no es "pre_aceptacion". Devuelve el canal cerrado, o null.
     */
    public function closePreAcceptanceChannelForApplication(QuoteRequestApplication $application): ?ChatChannel
    {
        $channel = $application->chatChannel()->first();

        if ($channel === null || $channel->status !== ChatChannel::STATUS_PRE_ACEPTACION) {
            return $channel;
        }

        $channel->status = ChatChannel::STATUS_CERRADO;
        $channel->save();

        return $channel->refresh();
    }

    // -------------------------------------------------------------------------
    // Tarea 13.3: envío, persistencia e historial de mensajes
    // -------------------------------------------------------------------------

    /**
     * Determina si un Usuario es participante (Cliente o Prestador) de un canal.
     *
     * El control de acceso de los endpoints de mensajería se apoya en este
     * método: solo los participantes pueden listar canales, leer el historial o
     * enviar mensajes (Requisitos 11.4, 11.6; el resto obtiene 403).
     */
    public function isParticipant(ChatChannel $channel, User $user): bool
    {
        return (int) $channel->client_user_id === (int) $user->id
            || (int) $channel->provider_user_id === (int) $user->id;
    }

    /**
     * Persiste un mensaje enviado por un participante del canal y emite el
     * evento de broadcasting en tiempo real (Requisitos 11.4, 11.5, 11.7).
     *
     * Reglas:
     *  - El contenido ya viene validado (1-2000 caracteres) por el Form Request
     *    (Requisito 11.8); aquí se recorta el whitespace de los extremos.
     *  - Un canal en estado "cerrado" rechaza el envío sin persistir, lanzando
     *    ChatMessageException::channelClosed() (HTTP 409, Requisito 11.7). Solo
     *    los canales "activo" y "pre_aceptacion" admiten mensajes.
     *  - Se persiste canal, emisor, contenido y marca de tiempo UTC de envío
     *    (`sent_at_utc`, Requisito 11.5).
     *
     * Tras persistir, transmite el evento NewChatMessage por Reverb al canal
     * privado `private-chat.{channelId}` (Requisito 11.4) y notifica al receptor
     * del mensaje a través del Gestor_Notificaciones (Requisito 11.9).
     *
     * @throws ChatMessageException si el canal está "cerrado".
     */
    public function sendMessage(ChatChannel $channel, User $sender, string $content): ChatMessage
    {
        if ($channel->status === ChatChannel::STATUS_CERRADO) {
            throw ChatMessageException::channelClosed();
        }

        $message = $channel->messages()->create([
            'sender_user_id' => $sender->id,
            'content' => trim($content),
            'sent_at_utc' => now(),
        ]);

        // Broadcast en tiempo real a los participantes del canal (Requisito 11.4).
        NewChatMessage::dispatch($message);

        // Tarea 13.6 (Requisito 11.9): notificar al receptor (el otro
        // participante, nunca el emisor). Best-effort incondicional: el servidor
        // no conoce con fiabilidad si el receptor tiene el canal abierto.
        $this->notifyRecipient($channel, $sender, $message);

        return $message;
    }

    /**
     * Emite una notificación in-app al receptor de un mensaje de Chat: el
     * participante del canal que NO es el emisor (Requisito 11.9).
     *
     * Si por alguna razón no se puede determinar un receptor distinto del
     * emisor (dato inconsistente), no se emite nada.
     */
    private function notifyRecipient(ChatChannel $channel, User $sender, ChatMessage $message): void
    {
        $recipientUserId = (int) $channel->client_user_id === (int) $sender->id
            ? (int) $channel->provider_user_id
            : (int) $channel->client_user_id;

        if ($recipientUserId <= 0 || $recipientUserId === (int) $sender->id) {
            return;
        }

        $this->notifications->notify($recipientUserId, 'chat.message_received', [
            'channel_id' => $channel->id,
            'message_id' => $message->id,
            'sender_user_id' => (int) $sender->id,
            'actor_name' => $sender->name,
            'preview' => mb_substr($message->content, 0, 120),
        ]);
    }

    /**
     * Historial de mensajes de un canal, ordenado cronológicamente de más
     * antiguo a más reciente, con paginación por cursor de 50 por página
     * (Requisito 11.6).
     *
     * Se usa paginación por cursor (no por offset) porque el historial de un
     * canal puede crecer mucho; la paginación por cursor es O(1) respecto a la
     * profundidad del historial (ver design.md, decisión 6).
     *
     * @return CursorPaginator<ChatMessage>
     */
    public function messagesFor(ChatChannel $channel, int $perPage = 50, ?string $cursor = null): CursorPaginator
    {
        return $channel->messages()
            ->orderBy('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }

    /**
     * Lista los canales de Chat en los que el Usuario participa (como Cliente o
     * Prestador), ordenados por actividad más reciente.
     *
     * @return Collection<int, ChatChannel>
     */
    public function channelsForUser(User $user): Collection
    {
        return ChatChannel::query()
            // Contexto para identificar cada conversación en la UI: las partes y
            // la Solicitud de origen (vía la postulación o vía el Trabajo).
            ->with(self::CONTEXT_RELATIONS)
            ->where(function ($query) use ($user): void {
                $query->where('client_user_id', $user->id)
                    ->orWhere('provider_user_id', $user->id);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }
}

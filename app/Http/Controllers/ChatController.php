<?php

namespace App\Http\Controllers;

use App\Exceptions\ChatMessageException;
use App\Http\Requests\SendChatMessageRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Services\ChatService;
use App\Support\AvatarPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de Chat (/api/v1/chat) — tarea 13.3.
 *
 * Expone el envío, el historial y el listado de canales de mensajería
 * (Requisitos 11.4, 11.5, 11.6, 11.7, 11.8). Todos los endpoints requieren
 * autenticación (auth:sanctum en la ruta) y, además, que el usuario sea
 * participante del canal (Cliente o Prestador); en otro caso se responde 403.
 *
 *   - GET  /chat/channels                     Listar canales del usuario.
 *   - GET  /chat/channels/{channel}/messages  Historial paginado por cursor (50).
 *   - POST /chat/channels/{channel}/messages  Enviar mensaje.
 *
 * Errores de negocio:
 *   - 403 si el usuario no es participante del canal.
 *   - 409 si el canal está "cerrado" al enviar un mensaje (Requisito 11.7).
 *   - 422 si el contenido es vacío o excede 2000 caracteres (Requisito 11.8),
 *     resuelto por SendChatMessageRequest antes de llegar al servicio.
 */
class ChatController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly ChatService $service)
    {
    }

    /**
     * GET /api/v1/chat/channels  (auth:sanctum)
     *
     * Lista los canales de Chat en los que participa el usuario autenticado.
     */
    public function channels(Request $request): JsonResponse
    {
        $channels = $this->service->channelsForUser($request->user())
            ->map(fn (ChatChannel $channel) => $this->presentChannel($channel))
            ->all();

        $message = $channels === []
            ? 'No tienes canales de chat.'
            : 'Listado de canales de chat.';

        return $this->ok(data: ['channels' => $channels], message: $message);
    }

    /**
     * GET /api/v1/chat/channels/{channel}/messages  (auth:sanctum — participante)
     *
     * Historial de mensajes del canal, ordenado cronológicamente (más antiguo a
     * más reciente) y paginado por cursor en páginas de hasta 50 (Requisito 11.6).
     */
    public function messages(Request $request, ChatChannel $channel): JsonResponse
    {
        if ($denied = $this->denyIfNotParticipant($request, $channel)) {
            return $denied;
        }

        $paginator = $this->service->messagesFor(
            $channel,
            perPage: 50,
            cursor: $request->query('cursor'),
        );

        $items = array_map(
            fn (ChatMessage $message) => $this->presentMessage($message),
            $paginator->items(),
        );

        return \App\Http\Responses\ApiResponse::success(
            data: ['messages' => $items],
            message: 'Historial de mensajes del canal.',
            meta: [
                'per_page' => $paginator->perPage(),
                'next_cursor' => optional($paginator->nextCursor())->encode(),
                'prev_cursor' => optional($paginator->previousCursor())->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    /**
     * POST /api/v1/chat/channels/{channel}/messages  (auth:sanctum — participante)
     *
     * Envía un mensaje al canal. El contenido (1-2000 caracteres) se valida en
     * SendChatMessageRequest (422 sin persistir, Requisito 11.8). Un canal
     * "cerrado" produce 409 (Requisito 11.7). Al persistir, se transmite el
     * evento por Reverb (Requisito 11.4) y se devuelve el mensaje creado.
     */
    public function send(SendChatMessageRequest $request, ChatChannel $channel): JsonResponse
    {
        if ($denied = $this->denyIfNotParticipant($request, $channel)) {
            return $denied;
        }

        try {
            $message = $this->service->sendMessage(
                $channel,
                $request->user(),
                $request->validated()['content'],
            );
        } catch (ChatMessageException $e) {
            return $this->error($e->getMessage(), status: $e->getStatus());
        }

        return $this->created(
            data: ['message' => $this->presentMessage($message)],
            message: 'Mensaje enviado correctamente.',
        );
    }

    /**
     * Rechaza con 403 si el usuario autenticado no es participante del canal.
     * Devuelve null cuando sí lo es (acción permitida).
     */
    private function denyIfNotParticipant(Request $request, ChatChannel $channel): ?JsonResponse
    {
        if (! $this->service->isParticipant($channel, $request->user())) {
            return $this->error(
                'No tienes permiso para acceder a este canal de chat.',
                status: 403,
            );
        }

        return null;
    }

    /**
     * Representación de un ChatMessage para la API.
     *
     * @return array<string, mixed>
     */
    private function presentMessage(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'channel_id' => $message->channel_id,
            'sender_user_id' => $message->sender_user_id,
            'content' => $message->content,
            'sent_at_utc' => optional($message->sent_at_utc)->toIso8601String(),
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }

    /**
     * Representación de un ChatChannel para la API.
     *
     * @return array<string, mixed>
     */
    private function presentChannel(ChatChannel $channel): array
    {
        $channel->loadMissing(ChatService::CONTEXT_RELATIONS);

        // Solicitud de origen: por la postulación (pre-aceptación) o por la
        // cotización del Trabajo. Es null en Trabajos de cotización directa.
        $quoteRequest = $channel->quoteRequestApplication?->quoteRequest
            ?? $channel->job?->quote?->quoteRequest;

        return [
            'id' => $channel->id,
            'job_id' => $channel->job_id,
            'quote_request_application_id' => $channel->quote_request_application_id,
            'client_user_id' => $channel->client_user_id,
            'client_name' => $channel->client?->name,
            ...AvatarPresenter::thumb($channel->client, 'client_'),
            'provider_user_id' => $channel->provider_user_id,
            'provider_name' => $channel->provider?->name,
            ...AvatarPresenter::thumb($channel->provider, 'provider_'),
            // Origen de la conversación: postulación a una Solicitud o Trabajo.
            'context' => $channel->job_id !== null ? 'trabajo' : 'postulacion',
            'quote_request' => $quoteRequest === null ? null : [
                'id' => $quoteRequest->id,
                'title' => $quoteRequest->title,
                'status' => $quoteRequest->status,
                'category_name' => $quoteRequest->category?->name,
                'city_name' => $quoteRequest->city?->name,
            ],
            'job_status' => $channel->job?->status,
            'quote_id' => $channel->job?->quote_id,
            'status' => $channel->status,
            'created_at' => optional($channel->created_at)->toIso8601String(),
            'updated_at' => optional($channel->updated_at)->toIso8601String(),
        ];
    }
}

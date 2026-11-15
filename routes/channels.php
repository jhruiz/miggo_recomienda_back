<?php

use App\Models\ChatChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal privado de notificaciones in-app: solo el propio usuario puede
// suscribirse a su canal (Requisito 11 / 12). Autoriza `private-user.{userId}`.
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Canal privado de Chat: solo los participantes del canal (Cliente o Prestador)
// pueden suscribirse (Requisito 11). Autoriza `private-chat.{channelId}`.
Broadcast::channel('chat.{channelId}', function ($user, $channelId) {
    $channel = ChatChannel::find($channelId);

    return $channel && (
        (int) $user->id === (int) $channel->client_user_id ||
        (int) $user->id === (int) $channel->provider_user_id
    );
});

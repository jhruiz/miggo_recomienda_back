<?php

namespace App\Support;

use App\Models\User;

final class AvatarPresenter
{
    /**
     * @return array{avatar_url: ?string, avatar_thumb_url: ?string}
     */
    public static function full(?User $user): array
    {
        return [
            'avatar_url' => $user?->avatarUrl(),
            'avatar_thumb_url' => $user?->avatarThumbUrl(),
        ];
    }

    /**
     * Thumbnail only, with a key prefix: thumb($u, 'provider_') => ['provider_avatar_thumb_url' => ?string].
     *
     * @return array<string, ?string>
     */
    public static function thumb(?User $user, string $prefix = ''): array
    {
        return [$prefix.'avatar_thumb_url' => $user?->avatarThumbUrl()];
    }
}

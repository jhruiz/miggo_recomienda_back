<?php

namespace App\Enums;

enum FeedEventType: string
{
    case View = 'view';
    case Like = 'like';
    case Unlike = 'unlike';
    case Save = 'save';
    case Unsave = 'unsave';
    case OpenProviderProfile = 'open_provider_profile';
    case OpenDetail = 'open_detail';

    /**
     * Whether the client may report this event type (the rest are server-generated).
     */
    public function isClientOriginated(): bool
    {
        return match ($this) {
            self::View, self::OpenProviderProfile, self::OpenDetail => true,
            default => false,
        };
    }
}

<?php

namespace App\Http\Controllers\Concerns;

use App\Models\FeedPreference;
use App\Models\User;

/**
 * Meta común de los listados de pestaña del Muro (per_page, registro de comportamiento y aviso de privacidad).
 */
trait BuildsWallListingMeta
{
    /**
     * @return array{per_page: int, behavior_logging: bool, privacy_answered: bool}
     */
    protected function wallListingMeta(User $user, int $perPage): array
    {
        $preference = FeedPreference::query()->where('user_id', $user->id)->first();

        return [
            'per_page' => $perPage,
            'behavior_logging' => (bool) ($preference?->behavior_logging ?? false),
            'privacy_answered' => $preference?->privacy_notice_answered_at !== null,
        ];
    }
}

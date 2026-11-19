<?php

namespace App\Http\Resources;

use App\Support\PrivacySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resumen de privacidad del Muro.
 *
 * @property PrivacySummary $resource
 */
class FeedPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'status' => $summary->status,
            'behavior_logging' => $summary->behaviorLogging,
            'consented_at' => $summary->consentedAt?->toIso8601String(),
            'events_total' => $summary->eventsTotal,
            'events_by_type' => $summary->eventsByType,
            'events_by_subject_type' => $summary->eventsBySubjectType,
            'oldest_event_at' => $summary->oldestEventAt?->toIso8601String(),
        ];
    }
}

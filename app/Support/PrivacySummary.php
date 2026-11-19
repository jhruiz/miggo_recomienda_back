<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Resumen de privacidad del Muro: estado de la preferencia y conteos del historial.
 */
final readonly class PrivacySummary
{
    public const STATUS_UNANSWERED = 'unanswered';

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @param  array<string, int>  $eventsByType
     * @param  array<string, int>  $eventsBySubjectType
     */
    public function __construct(
        public string $status,
        public bool $behaviorLogging,
        public ?CarbonInterface $consentedAt,
        public int $eventsTotal,
        public array $eventsByType,
        public ?CarbonInterface $oldestEventAt,
        public array $eventsBySubjectType = [],
    ) {}
}

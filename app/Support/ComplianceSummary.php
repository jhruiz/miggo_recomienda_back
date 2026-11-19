<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Resumen_Cumplimiento: días calendario entre la entrega estimada y la real.
 */
final readonly class ComplianceSummary
{
    private const SECONDS_PER_DAY = 86400;

    /**
     * @return array{kind: 'early'|'on_time'|'late'|'unknown', days: ?int, text: string}
     */
    public static function fromDates(?CarbonInterface $estimated, ?CarbonInterface $actual): array
    {
        if ($estimated === null || $actual === null) {
            return ['kind' => 'unknown', 'days' => null, 'text' => 'Sin datos de cumplimiento'];
        }

        $difference = intdiv(
            CarbonImmutable::parse($estimated->toDateString(), 'UTC')->getTimestamp()
            - CarbonImmutable::parse($actual->toDateString(), 'UTC')->getTimestamp(),
            self::SECONDS_PER_DAY,
        );

        if ($difference === 0) {
            return ['kind' => 'on_time', 'days' => 0, 'text' => 'Entregó a tiempo'];
        }

        $days = abs($difference);
        $unit = $days === 1 ? 'día' : 'días';

        return $difference > 0
            ? ['kind' => 'early', 'days' => $days, 'text' => "{$days} {$unit} antes"]
            : ['kind' => 'late', 'days' => $days, 'text' => "{$days} {$unit} después"];
    }
}

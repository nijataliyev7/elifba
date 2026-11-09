<?php

namespace App\Domain\Play\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The sender's clock on a board event (`client_at`): an ISO 8601 instant,
 * written back in UTC with milliseconds, as JavaScript's `toISOString()`
 * writes it. Kept for latency diagnostics only.
 */
final class ClientTime
{
    public static function parse(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    public static function format(?CarbonInterface $time): ?string
    {
        return $time?->toImmutable()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}

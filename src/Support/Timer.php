<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Support;

/**
 * Duration-only value object for time-based event configuration.
 *
 * Used with `after` and `every` keys on transitions to define
 * when events should auto-trigger.
 */
class Timer
{
    private function __construct(
        private readonly int $totalSeconds,
    ) {}

    public static function seconds(int $value): self
    {
        return new self($value);
    }

    public static function minutes(int $value): self
    {
        return new self($value * 60);
    }

    public static function hours(int $value): self
    {
        return new self($value * 3600);
    }

    public static function days(int $value): self
    {
        return new self($value * 86400);
    }

    public static function weeks(int $value): self
    {
        return new self($value * 604800);
    }

    public function inSeconds(): int
    {
        return $this->totalSeconds;
    }
}

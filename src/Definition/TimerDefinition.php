<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Support\Timer;

/**
 * Value object parsed from `after`/`every` keys on a transition config.
 *
 * Holds the timer type (after or every), duration, and optional
 * recurring config (max, then).
 */
class TimerDefinition
{
    /**
     * @param  string  $type  'after' (one-shot) or 'every' (recurring).
     * @param  int  $delaySeconds  Duration in seconds.
     * @param  string  $eventName  The event type this timer is attached to.
     * @param  string  $stateId  The state ID this timer belongs to.
     * @param  int|null  $max  Maximum fire count (every only).
     * @param  string|null  $then  Event to send after max reached (every only).
     */
    public function __construct(
        public readonly string $type,
        public readonly int $delaySeconds,
        public readonly string $eventName,
        public readonly string $stateId,
        public readonly ?int $max = null,
        public readonly ?string $then = null,
    ) {}

    /**
     * Build a TimerDefinition from an `after` key on a transition.
     */
    public static function fromAfter(Timer $timer, string $eventName, string $stateId): self
    {
        return new self(
            type: 'after',
            delaySeconds: $timer->inSeconds(),
            eventName: $eventName,
            stateId: $stateId,
        );
    }

    /**
     * Build a TimerDefinition from an `every` key on a transition.
     */
    public static function fromEvery(Timer $timer, string $eventName, string $stateId, ?int $max = null, ?string $then = null): self
    {
        return new self(
            type: 'every',
            delaySeconds: $timer->inSeconds(),
            eventName: $eventName,
            stateId: $stateId,
            max: $max,
            then: $then,
        );
    }

    /**
     * Whether this is a one-shot timer.
     */
    public function isAfter(): bool
    {
        return $this->type === 'after';
    }

    /**
     * Whether this is a recurring timer.
     */
    public function isEvery(): bool
    {
        return $this->type === 'every';
    }

    /**
     * Generate the timer key for dedup tracking.
     *
     * Format: {state_id}:{event_name}:{delay_seconds}
     */
    public function key(): string
    {
        return "{$this->stateId}:{$this->eventName}:{$this->delaySeconds}";
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Closure;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Value object representing a single schedule definition.
 *
 * Pairs an event type with an optional resolver that determines
 * which machine instances should receive the scheduled event.
 */
class ScheduleDefinition
{
    public function __construct(
        public readonly string $eventType,
        public readonly string|Closure|null $resolver = null,
    ) {}

    /**
     * Create a ScheduleDefinition from a schedules config entry.
     *
     * @param  string  $key  Event type string or EventBehavior FQCN
     * @param  string|Closure|null  $resolver  Resolver class, closure, or null for auto-detect
     */
    public static function fromConfig(string $key, string|Closure|null $resolver): self
    {
        return new self(
            eventType: self::resolveEventType($key),
            resolver: $resolver,
        );
    }

    /**
     * Whether this schedule has an explicit resolver (class or closure).
     *
     * When false, the command auto-detects target states from the definition's idMap.
     */
    public function hasResolver(): bool
    {
        return $this->resolver !== null;
    }

    /**
     * Resolve an event key to its SCREAMING_SNAKE_CASE event type.
     *
     * Accepts either a plain string ('CHECK_EXPIRY') or an EventBehavior class FQCN.
     * Same logic as EndpointDefinition::resolveEventType().
     */
    private static function resolveEventType(string $key): string
    {
        if (is_subclass_of($key, EventBehavior::class)) {
            return $key::getType();
        }

        return $key;
    }
}

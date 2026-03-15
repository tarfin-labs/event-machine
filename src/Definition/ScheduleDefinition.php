<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Closure;
use Tarfinlabs\EventMachine\Routing\EndpointDefinition;

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
            eventType: EndpointDefinition::resolveEventType($key),
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
}

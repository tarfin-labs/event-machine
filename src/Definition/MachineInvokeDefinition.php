<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Closure;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Routing\EndpointDefinition;
use Tarfinlabs\EventMachine\Routing\ForwardedEndpointDefinition;

/**
 * Value object that holds machine/job delegation configuration.
 *
 * Parsed from the `machine` or `job` key in a state definition config.
 * Handles context transfer resolution via the `with` key.
 */
class MachineInvokeDefinition
{
    /**
     * @param  string  $machineClass  The FQCN of the child machine definition (empty string for job actors).
     * @param  array|Closure|null  $with  Context transfer configuration (3 formats).
     * @param  array  $forward  Event types to forward to the child machine.
     * @param  bool  $async  Whether the child runs asynchronously.
     * @param  string|null  $queue  Queue name for async execution.
     * @param  string|null  $connection  Queue connection for async execution.
     * @param  int|null  $timeout  Timeout in seconds for @timeout handling.
     * @param  int|null  $retry  Number of retry attempts for async execution.
     * @param  string|null  $jobClass  The FQCN of the job class (for job actors).
     * @param  string|null  $target  Target state for fire-and-forget jobs (no @done).
     */
    public function __construct(
        public readonly string $machineClass = '',
        public readonly array|Closure|null $with = null,
        public readonly array $forward = [],
        public readonly bool $async = false,
        public readonly ?string $queue = null,
        public readonly ?string $connection = null,
        public readonly ?int $timeout = null,
        public readonly ?int $retry = null,
        public readonly ?string $jobClass = null,
        public readonly ?string $target = null,
    ) {}

    /**
     * Whether this invoke definition is a job actor (not a machine).
     */
    public function isJob(): bool
    {
        return $this->jobClass !== null;
    }

    /**
     * Resolve the child machine's initial context from the parent context.
     *
     * Supports 3 formats for the `with` key:
     * - Format 1: Same-name array ['order_id'] → child gets order_id from parent
     * - Format 2: Key mapping ['amount' => 'total_amount'] → child amount = parent total_amount
     * - Format 3: Closure fn(ContextManager $ctx) => ['key' => 'value']
     *
     * @param  ContextManager  $parentContext  The parent machine's context.
     *
     * @return array The resolved child context data.
     */
    public function resolveChildContext(ContextManager $parentContext): array
    {
        if ($this->with === null) {
            return [];
        }

        if ($this->with instanceof Closure) {
            return ($this->with)($parentContext);
        }

        $childContext = [];

        foreach ($this->with as $key => $value) {
            if (is_int($key)) {
                // Format 1: ['order_id'] → same name
                $childContext[$value] = $parentContext->get($value);
            } else {
                // Format 2: ['amount' => 'total_amount'] → rename
                $childContext[$key] = $parentContext->get($value);
            }
        }

        return $childContext;
    }

    /**
     * Resolve whether an event type should be forwarded to the child machine.
     *
     * Supports two formats in the `forward` array:
     * - Plain: `['APPROVE_PAYMENT']` → forward as-is
     * - Rename: `['UPDATE_SHIPPING_INFO' => 'UPDATE_INFO']` → rename for child
     *
     * @param  string  $eventType  The parent event type.
     *
     * @return string|null The child event type to forward, or null if not forwarded.
     */
    public function resolveForwardEvent(string $eventType): ?string
    {
        if ($this->forward === []) {
            return null;
        }

        foreach ($this->forward as $key => $value) {
            if (is_int($key) && is_string($value) && $value === $eventType) {
                // Format 1: plain forward as-is
                return $eventType;
            }

            if (is_string($key) && $key === $eventType) {
                if (is_string($value)) {
                    // Format 2: rename — parent event → child event
                    return $value;
                }

                if (is_array($value)) {
                    // Format 3: full config — child_event or same name
                    return $value['child_event'] ?? $eventType;
                }
            }
        }

        return null;
    }

    /**
     * Check if this invoke definition has any forward events configured.
     */
    public function hasForward(): bool
    {
        return $this->forward !== [];
    }

    /**
     * Resolve forward entries into ForwardedEndpointDefinition objects.
     *
     * Uses the child machine's definition to discover EventBehavior classes.
     *
     * @param  MachineDefinition  $childDefinition  The child machine's definition.
     *
     * @return array<string, ForwardedEndpointDefinition> Keyed by parent event type.
     */
    public function resolveForwardEndpoints(MachineDefinition $childDefinition): array
    {
        if ($this->forward === []) {
            return [];
        }

        $endpoints = [];

        foreach ($this->forward as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // Format 1: plain — 'PROVIDE_CARD'
                $parentEventType = $value;
                $childEventType  = $value;
                $config          = [];
            } elseif (is_string($key) && is_string($value)) {
                // Format 2: rename — 'CANCEL_ORDER' => 'ABORT'
                $parentEventType = $key;
                $childEventType  = $value;
                $config          = [];
            } elseif (is_string($key) && is_array($value)) {
                // Format 3: full config
                $parentEventType = $key;
                $childEventType  = $value['child_event'] ?? $key;
                $config          = $value;
            } else {
                continue;
            }

            // Discover child's EventBehavior class
            $childEventClass = $childDefinition->behavior['events'][$childEventType] ?? null;

            $endpoints[$parentEventType] = new ForwardedEndpointDefinition(
                parentEventType: $parentEventType,
                childEventType: $childEventType,
                childMachineClass: $this->machineClass,
                childEventClass: $childEventClass ?? '',
                uri: $config['uri'] ?? EndpointDefinition::generateUri($parentEventType),
                method: $config['method'] ?? 'POST',
                actionClass: $config['action'] ?? null,
                output: $config['output'] ?? null,
                statusCode: $config['status'] ?? null,
                middleware: $config['middleware'] ?? [],
                availableEvents: $config['available_events'] ?? null,
            );
        }

        return $endpoints;
    }
}

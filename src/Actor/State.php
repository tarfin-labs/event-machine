<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Symfony\Component\Uid\Ulid;
use Illuminate\Support\Facades\Log;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineInvokeDefinition;

/**
 * Class State.
 *
 * Represents a state in an event machine.
 */
class State implements \JsonSerializable
{
    /**
     * Represents the value of the state.
     *
     * @var array<string> The value to be stored.
     */
    public array $value;

    /**
     * Active child machine root_event_ids for the current state.
     *
     * @var array<string>
     */
    public array $activeChildren = [];

    /**
     * The @done.{state} route key that was matched during child completion routing.
     *
     * Set to the final state key when a specific @done.{state} branch fires.
     * Set to null when the catch-all @done fires. Transient — not persisted.
     */
    public ?string $lastChildDoneRoute = null;

    /**
     * The original event that triggered the current macrostep.
     *
     * Preserved through @always chains so that behaviors (actions, guards,
     * calculators) receive the real triggering event instead of the synthetic
     * '@always' event. Transient — not persisted to DB or queue payloads.
     */
    public ?EventBehavior $triggeringEvent = null;

    /**
     * Constructs a new instance of the class.
     *
     * @param  ContextManager  $context  The context manager instance.
     * @param  StateDefinition|null  $currentStateDefinition  The current state definition, or null if not set.
     * @param  EventBehavior|null  $currentEventBehavior  The current event behavior, or null if not set.
     * @param  EventCollection|null  $history  The history collection, or null if not set.
     */
    public function __construct(
        public ContextManager $context,
        public ?StateDefinition $currentStateDefinition,
        public ?EventBehavior $currentEventBehavior = null,
        public ?EventCollection $history = null,
    ) {
        $this->history ??= new EventCollection();

        $this->updateMachineValueFromState();
    }

    /**
     * Updates the machine value based on the current state definition.
     *
     * For parallel states, collects all active leaf state IDs into the array.
     * For non-parallel states, uses a single-element array.
     */
    protected function updateMachineValueFromState(): void
    {
        if (!$this->currentStateDefinition instanceof StateDefinition) {
            $this->value = [];

            return;
        }

        if ($this->currentStateDefinition->type === StateDefinitionType::PARALLEL) {
            // For parallel states, collect all initial states from all regions
            $this->value = array_map(
                fn (StateDefinition $state): string => $state->id,
                $this->currentStateDefinition->findAllInitialStateDefinitions()
            );
        } else {
            $this->value = [$this->currentStateDefinition->id];
        }
    }

    /**
     * Create a lightweight State instance for testing without a full MachineDefinition.
     *
     * @param  ContextManager  $context  A ContextManager instance.
     * @param  StateDefinition|null  $currentStateDefinition  Optional state definition.
     * @param  EventBehavior|null  $currentEventBehavior  Optional event behavior.
     */
    public static function forTesting(
        ContextManager $context,
        ?StateDefinition $currentStateDefinition = null,
        ?EventBehavior $currentEventBehavior = null,
    ): self {
        return new self(
            context: $context,
            currentStateDefinition: $currentStateDefinition,
            currentEventBehavior: $currentEventBehavior,
            history: new EventCollection(),
        );
    }

    /**
     * Sets the current state definition for the machine.
     *
     * @param  StateDefinition  $stateDefinition  The state definition to set.
     *
     * @return self The current object instance.
     */
    public function setCurrentStateDefinition(StateDefinition $stateDefinition): self
    {
        $this->currentStateDefinition = $stateDefinition;
        $this->updateMachineValueFromState();

        return $this;
    }

    /**
     * Sets the internal event behavior for the machine.
     *
     * @param  InternalEvent  $type  The internal event type.
     * @param  string|null  $placeholder  The optional placeholder parameter.
     * @param  array|null  $payload  The optional payload array.
     *
     * @return self The current object instance.
     */
    public function setInternalEventBehavior(
        InternalEvent $type,
        ?string $placeholder = null,
        ?array $payload = null,
        bool $shouldLog = false,
    ): self {
        $eventDefinition = new EventDefinition(
            type: $type->generateInternalEventName(
                machineId: $this->currentStateDefinition->machine->id,
                placeholder: $placeholder
            ),
            payload: $payload,
            source: SourceType::INTERNAL,
        );

        return $this->setCurrentEventBehavior(currentEventBehavior: $eventDefinition, shouldLog: $shouldLog);
    }

    /**
     * Sets the current event behavior for the machine.
     *
     * @param  EventBehavior  $currentEventBehavior  The event behavior to set.
     *
     * @return self The current object instance.
     */
    public function setCurrentEventBehavior(EventBehavior $currentEventBehavior, bool $shouldLog = false): self
    {
        $this->currentEventBehavior = $currentEventBehavior;

        $id    = Ulid::generate();
        $count = count($this->history) + 1;

        $rootEventId = $this->history->first()->id ?? $id;

        $this->history->push(
            new MachineEvent([
                'id'              => $id,
                'sequence_number' => $count,
                'created_at'      => now(),
                'machine_id'      => $this->currentStateDefinition->machine->id,
                'machine_value'   => $this->value,
                'root_event_id'   => $rootEventId,
                'source'          => $currentEventBehavior->source,
                'type'            => $currentEventBehavior->type,
                'payload'         => $currentEventBehavior->payload(),
                'version'         => $currentEventBehavior->version,
                'context'         => $this->context->toArray(),
                'meta'            => $this->currentStateDefinition->meta,
            ])
        );

        if ($shouldLog) {
            Log::debug("[{$rootEventId}] {$currentEventBehavior->type}");
        }

        return $this;
    }

    /**
     * Checks if the given value matches any of the current state's values.
     *
     * For parallel states, checks if the value is in any of the active regions.
     *
     * @param  string  $value  The value to be checked.
     *
     * @return bool Returns true if the value matches any of the current state's values; otherwise, returns false.
     */
    public function matches(string $value): bool
    {
        if (!$this->currentStateDefinition instanceof StateDefinition) {
            return false;
        }

        $machineId = $this->currentStateDefinition->machine->id;

        if (!str_starts_with($value, $machineId)) {
            $value = $machineId.'.'.$value;
        }

        return in_array($value, $this->value, true);
    }

    /**
     * Checks if all the given values match the current state's values.
     *
     * Useful for verifying multiple regions in parallel states are in expected states.
     *
     * @param  array<string>  $values  The values to be checked.
     *
     * @return bool Returns true if all values match; otherwise, returns false.
     */
    public function matchesAll(array $values): bool
    {
        if (!$this->currentStateDefinition instanceof StateDefinition) {
            return false;
        }

        $machineId = $this->currentStateDefinition->machine->id;

        foreach ($values as $value) {
            if (!str_starts_with($value, $machineId)) {
                $value = $machineId.'.'.$value;
            }

            if (!in_array($value, $this->value, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if the current state is within a parallel state.
     *
     * @return bool Returns true if the machine is currently in a parallel state.
     */
    public function isInParallelState(): bool
    {
        return count($this->value) > 1;
    }

    /**
     * Sets multiple state values for parallel states.
     *
     * @param  array<string>  $values  The state IDs to set.
     *
     * @return self The current object instance.
     */
    public function setValues(array $values): self
    {
        $this->value = $values;

        return $this;
    }

    // region Active Children

    /**
     * Add a child machine to the active children list.
     *
     * @param  string  $childRootEventId  The child machine's root_event_id.
     */
    public function addActiveChild(string $childRootEventId): self
    {
        if (!in_array($childRootEventId, $this->activeChildren, true)) {
            $this->activeChildren[] = $childRootEventId;
        }

        return $this;
    }

    /**
     * Remove a child machine from the active children list.
     *
     * @param  string  $childRootEventId  The child machine's root_event_id.
     */
    public function removeActiveChild(string $childRootEventId): self
    {
        $this->activeChildren = array_values(
            array_filter(
                $this->activeChildren,
                fn (string $id): bool => $id !== $childRootEventId
            )
        );

        return $this;
    }

    /**
     * Check if a child machine is in the active children list.
     *
     * @param  string  $childRootEventId  The child machine's root_event_id.
     */
    public function hasActiveChild(string $childRootEventId): bool
    {
        return in_array($childRootEventId, $this->activeChildren, true);
    }

    /**
     * Check if there are any active child machines.
     */
    public function hasActiveChildren(): bool
    {
        return $this->activeChildren !== [];
    }

    // endregion

    // region Forwarded Child State

    /** The child machine's state after a forward event was processed. */
    private ?self $forwardedChildState = null;

    /**
     * Stash the child machine's state after a successful forward.
     *
     * Called by tryForwardEventToChild() so the controller can
     * include child state in the HTTP response without a second DB query.
     */
    public function setForwardedChildState(self $childState): void
    {
        $this->forwardedChildState = $childState;
    }

    /**
     * Get the forwarded child state, or null if no forward happened.
     */
    public function getForwardedChildState(): ?self
    {
        return $this->forwardedChildState;
    }

    // endregion

    // region Available Events

    /**
     * Get the events that can currently be sent to this machine.
     *
     * Returns parent on-events (non-internal) + forward events (if child accepts them).
     * Core introspection method — used by HTTP responses, TestMachine, toArray(), etc.
     *
     * @return array<int, array{type: string, source: string, region?: string}>
     */
    public function availableEvents(): array
    {
        if (!$this->currentStateDefinition instanceof StateDefinition) {
            return [];
        }

        // For parallel states, collect from all active regions
        if ($this->isInParallelState()) {
            return $this->availableEventsForParallelState();
        }

        $events = [];

        // 1. Parent's own on-events (non-internal)
        if ($this->currentStateDefinition->transitionDefinitions !== null) {
            foreach (array_keys($this->currentStateDefinition->transitionDefinitions) as $eventName) {
                if ($this->isUserSendableEvent($eventName)) {
                    $events[] = ['type' => $eventName, 'source' => 'parent'];
                }
            }
        }

        // 2. Forward events (if delegating state with running child)
        if ($this->currentStateDefinition->hasMachineInvoke()) {
            $invokeDefinition = $this->currentStateDefinition->getMachineInvokeDefinition();

            if ($invokeDefinition->hasForward()) {
                $childStateDef = $this->resolveChildCurrentStateDef($invokeDefinition);

                foreach ($invokeDefinition->forward as $key => $value) {
                    $parentEventType = is_int($key) ? $value : $key;

                    if (!is_string($parentEventType)) {
                        continue;
                    }

                    $childEventType = $invokeDefinition->resolveForwardEvent($parentEventType);

                    if ($childEventType === null) {
                        continue;
                    }

                    // Only include if child's current state accepts this event
                    if ($childStateDef instanceof StateDefinition && $this->childStateAcceptsEvent($childStateDef, $childEventType)) {
                        $events[] = ['type' => $parentEventType, 'source' => 'forward'];
                    }
                }
            }
        }

        return $events;
    }

    /**
     * Collect available events from all active regions in a parallel state.
     *
     * @return array<int, array{type: string, source: string, region?: string}>
     */
    protected function availableEventsForParallelState(): array
    {
        $events        = [];
        $parallelState = $this->currentStateDefinition;

        if ($parallelState->stateDefinitions === null) {
            return $events;
        }

        foreach ($this->value as $activeStateId) {
            $activeStateDef = $parallelState->machine->idMap[$activeStateId] ?? null;

            if ($activeStateDef === null) {
                continue;
            }

            // Determine region name from the active state's path
            $regionName = $activeStateDef->parent?->key;

            if ($activeStateDef->transitionDefinitions !== null) {
                foreach ($activeStateDef->transitionDefinitions as $eventName => $td) {
                    if ($this->isUserSendableEvent($eventName)) {
                        $event = ['type' => $eventName, 'source' => 'parent'];

                        if ($regionName !== null) {
                            $event['region'] = $regionName;
                        }

                        $events[] = $event;
                    }
                }
            }
        }

        return $events;
    }

    /**
     * Check if an event type is user-sendable (not internal/timer).
     */
    protected function isUserSendableEvent(string $eventType): bool
    {
        // Exclude internal events
        if ($eventType === TransitionProperty::Always->value) {
            return false;
        }

        // Exclude @done, @fail, @timeout (they start with @)
        if (str_starts_with($eventType, '@')) {
            return false;
        }

        // Exclude internal event enums
        $internalTypes = array_map(
            fn (InternalEvent $e): string => $e->value,
            InternalEvent::cases()
        );

        return !in_array($eventType, $internalTypes, true);
    }

    /**
     * Resolve the child machine's current state definition for forward event checking.
     * Uses forwardedChildState if available, otherwise checks MachineCurrentState table.
     */
    protected function resolveChildCurrentStateDef(
        MachineInvokeDefinition $invokeDefinition,
    ): ?StateDefinition {
        // If we have a stashed child state from a recent forward, use it
        if ($this->forwardedChildState instanceof State) {
            return $this->forwardedChildState->currentStateDefinition;
        }

        // Otherwise, try lightweight lookup via MachineCurrentState table
        $rootEventId = $this->history->first()?->root_event_id;

        if ($rootEventId === null) {
            return null;
        }

        $childRecord = MachineChild::where('parent_root_event_id', $rootEventId)
            ->where('status', MachineChild::STATUS_RUNNING)
            ->first();

        if ($childRecord === null || $childRecord->child_root_event_id === null) {
            return null;
        }

        $childCurrentState = MachineCurrentState::where('root_event_id', $childRecord->child_root_event_id)->first();

        if ($childCurrentState === null) {
            return null;
        }

        $childDef = $invokeDefinition->machineClass::definition();

        return $childDef->idMap[$childCurrentState->state_id] ?? null;
    }

    /**
     * Check if a child state definition accepts a given event type.
     */
    protected function childStateAcceptsEvent(StateDefinition $childStateDef, string $eventType): bool
    {
        if ($childStateDef->transitionDefinitions === null) {
            return false;
        }

        return isset($childStateDef->transitionDefinitions[$eventType]);
    }

    // endregion

    /**
     * Serialize the state to an array.
     *
     * @return array{value: array<string>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'value'            => $this->value,
            'context'          => $this->context->toResponseArray(),
            'available_events' => $this->availableEvents(),
        ];
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array{value: array<string>, context: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

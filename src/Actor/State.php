<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Symfony\Component\Uid\Ulid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;

/**
 * Class State.
 *
 * Represents a state in an event machine.
 */
class State
{
    /**
     * Represents the value of the state.
     *
     * @var array<string> The value to be stored.
     */
    public array $value;

    /**
     * Constructs a new instance of the class.
     *
     * @param  ContextManager  $context The context manager instance.
     * @param  StateDefinition|null  $currentStateDefinition The current state definition, or null if not set.
     * @param  EventBehavior|null  $currentEventBehavior The current event behavior, or null if not set.
     * @param  Collection|null  $history The history collection, or null if not set.
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
     */
    protected function updateMachineValueFromState(): void
    {
        $this->value = [$this->currentStateDefinition->id];
    }

    /**
     * Sets the current state definition for the machine.
     *
     * @param  StateDefinition  $stateDefinition The state definition to set.
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
     * @param  InternalEvent  $type The internal event type.
     * @param  string|null  $placeholder The optional placeholder parameter.
     * @param  array|null  $payload The optional payload array.
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
     * @param  EventBehavior  $currentEventBehavior The event behavior to set.
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
                'machine_value'   => [$this->currentStateDefinition->id],
                'root_event_id'   => $rootEventId,
                'source'          => $currentEventBehavior->source,
                'type'            => $currentEventBehavior->type,
                'payload'         => $currentEventBehavior->payload,
                'version'         => $currentEventBehavior->version,
                'context'         => $this->context->toArray(),
                'meta'            => $this->currentStateDefinition->meta,
            ])
        );

        if ($shouldLog === true) {
            Log::debug("[{$rootEventId}] {$currentEventBehavior->type}");
        }

        return $this;
    }

    /**
     * Checks if the given value matches the current state's value.
     *
     * @param  string  $value The value to be checked.
     *
     * @return bool Returns true if the value matches the current state's value; otherwise, returns false.
     */
    public function matches(string $value): bool
    {
        $machineId = $this->currentStateDefinition->machine->id;

        if (!str_starts_with($value, $machineId)) {
            $value = $machineId.'.'.$value;
        }

        return $this->value[0] === $value;
    }
}

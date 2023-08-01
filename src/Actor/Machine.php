<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Stringable;
use JsonSerializable;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Casts\MachineCast;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Definition\SourceType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Exceptions\MachineDefinitionNotFoundException;

class Machine implements Castable, JsonSerializable, Stringable
{
    // region Fields

    public ?MachineDefinition $definition = null;
    public ?State $state                  = null;

    // endregion

    // region Constructors

    protected function __construct(
        MachineDefinition $definition,
    ) {
        $this->definition = $definition;
    }

    public static function withDefinition(MachineDefinition $definition): self
    {
        return new self($definition);
    }

    // endregion

    // region Machine Definition

    public static function definition(): ?MachineDefinition
    {
        return null;
    }

    // endregion

    // region Event Handling

    public static function create(
        ?MachineDefinition $definition = null,
        null|State|string $state = null,
    ): self {
        if ($definition === null) {
            $definition = static::definition();

            if ($definition === null) {
                throw MachineDefinitionNotFoundException::build();
            }
        }

        $machine = new self($definition);

        $machine->state = match (true) {
            $state === null         => $machine->definition->getInitialState(),
            $state instanceof State => $state,
            is_string($state)       => $machine->restoreStateFromRootEventId($state),
        };

        return $machine;
    }

    public function start(null|State|string $state = null): self
    {
        $this->state = match (true) {
            $state === null         => $this->definition->getInitialState(),
            $state instanceof State => $state,
            is_string($state)       => $this->restoreStateFromRootEventId($state),
        };

        return $this;
    }

    public function send(
        EventBehavior|array $event,
        bool $shouldPersist = true,
    ): State {
        $lastPreviousEventNumber = $this->state !== null
            ? $this->state->history->last()->sequence_number
            : 0;

        $this->state = $this->definition->transition($event, $this->state);

        if ($shouldPersist === true) {
            $this->persist();
        }

        $this->handleValidationGuards($lastPreviousEventNumber);

        return $this->state;
    }

    // endregion

    // region Recording State

    public function persist(): ?State
    {
        MachineEvent::upsert(
            values: $this->state->history->map(fn (MachineEvent $machineEvent) => array_merge($machineEvent->toArray(), [
                'created_at'    => $machineEvent->created_at->toDateTimeString(),
                'machine_value' => json_encode($machineEvent->machine_value, JSON_THROW_ON_ERROR),
                'payload'       => json_encode($machineEvent->payload, JSON_THROW_ON_ERROR),
                'context'       => json_encode($machineEvent->context, JSON_THROW_ON_ERROR),
                'meta'          => json_encode($machineEvent->meta, JSON_THROW_ON_ERROR),
            ]))->toArray(),
            uniqueBy: ['id']
        );

        return $this->state;
    }

    // endregion

    // region Restoring State

    /**
     * Restores the state of the machine from the given root event identifier.
     *
     * @param  string  $key The root event identifier to restore state from.
     *
     * @return State The restored state of the machine.
     */
    public function restoreStateFromRootEventId(string $key): State
    {
        $machineEvents = MachineEvent::query()
            ->where('root_event_id', $key)
            ->oldest('sequence_number')
            ->get();

        if ($machineEvents->isEmpty()) {
            throw RestoringStateException::build('Machine state not found.');
        }

        $lastMachineEvent = $machineEvents->last();

        return new State(
            context: $this->restoreContext($lastMachineEvent->context),
            currentStateDefinition: $this->restoreCurrentStateDefinition($lastMachineEvent->machine_value),
            currentEventBehavior: $this->restoreCurrentEventBehavior($lastMachineEvent),
            history: $machineEvents,
        );
    }

    /**
     * Restores the context using the persisted context data.
     *
     * @param  array  $persistedContext The persisted context data.
     *
     * @return ContextManager The restored context manager instance.
     */
    protected function restoreContext(array $persistedContext): ContextManager
    {
        if (!empty($this->definition->behavior['context'])) {
            /** @var ContextManager $contextClass */
            $contextClass = $this->definition->behavior['context'];

            return $contextClass::validateAndCreate($persistedContext);
        }

        return ContextManager::validateAndCreate(['data' => $persistedContext]);
    }

    /**
     * Restores the current state definition based on the given machine value.
     *
     * @param  array  $machineValue The machine value containing the ID of the state definition
     *
     * @return StateDefinition The restored current state definition
     */
    protected function restoreCurrentStateDefinition(array $machineValue): StateDefinition
    {
        return $this->definition->idMap[$machineValue[0]];
    }

    /**
     * Restores the current event behavior based on the given MachineEvent.
     *
     * @param  MachineEvent  $machineEvent The MachineEvent object representing the event.
     *
     * @return EventBehavior The restored EventBehavior object.
     */
    protected function restoreCurrentEventBehavior(MachineEvent $machineEvent): EventBehavior
    {
        if ($machineEvent->source === SourceType::INTERNAL) {
            return EventDefinition::from([
                'type'    => $machineEvent->type,
                'payload' => $machineEvent->payload,
                'version' => $machineEvent->version,
                'source'  => SourceType::INTERNAL,
            ]);
        }

        if (isset($this->definition->behavior[BehaviorType::Event->value][$machineEvent->type])) {
            /** @var EventBehavior $eventDefinitionClass */
            $eventDefinitionClass = $this
                ->definition
                ->behavior[BehaviorType::Event->value][$machineEvent->type];

            return $eventDefinitionClass::validateAndCreate($machineEvent->payload);
        }

        return EventDefinition::from([
            'type'    => $machineEvent->type,
            'payload' => $machineEvent->payload,
            'version' => $machineEvent->version,
            'source'  => SourceType::EXTERNAL,
        ]);
    }

    // endregion

    // region Protected Methods

    /**
     * Handles validation guards and throws an exception if any of them fail.
     */
    protected function handleValidationGuards(int $lastPreviousEventNumber): void
    {
        $machineId = $this->state->currentStateDefinition->machine->id;

        $failedGuardEvents = $this
            ->state
            ->history
            ->filter(fn (MachineEvent $machineEvent) => $machineEvent->sequence_number > $lastPreviousEventNumber)
            ->filter(fn (MachineEvent $machineEvent) => preg_match("/{$machineId}\.guard\..*\.fail/", $machineEvent->type));

        if ($failedGuardEvents->isNotEmpty()) {
            $errorsWithMessage = [];

            foreach ($failedGuardEvents as $failedGuardEvent) {
                $failedGuardType  = explode('.', $failedGuardEvent->type)[2];
                $failedGuardClass = $this->definition->behavior[BehaviorType::Guard->value][$failedGuardType];

                if (is_subclass_of($failedGuardClass, ValidationGuardBehavior::class)) {
                    $errorsWithMessage[$failedGuardEvent->type] = $failedGuardEvent->payload[$failedGuardEvent->type];
                }
            }

            throw MachineValidationException::withMessages($errorsWithMessage);
        }
    }

    // endregion

    // region Inteface Implementations

    /**
     * Get the name of the caster class to use when casting from / to this cast target.
     *
     * @param  array<mixed>  $arguments
     */
    public static function castUsing(array $arguments): string
    {
        return MachineCast::class;
    }

    /**
     * Returns the JSON serialized representation of the object.
     *
     * @return mixed The JSON serialized representation of the object.
     */
    public function jsonSerialize(): string
    {
        return $this->state->history->first()->root_event_id;
    }

    /**
     * Returns a string representation of the current object.
     *
     * @return string The string representation of the object.
     */
    public function __toString(): string
    {
        return $this->state->history->first()->root_event_id ?? '';
    }

    // endregion
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Stringable;
use JsonSerializable;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Casts\MachineCast;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Models\MachineEvent;
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

    /** The machine definition that this machine is based on */
    public ?MachineDefinition $definition = null;

    /** The current state of the machine */
    public ?State $state = null;

    // endregion

    // region Constructors

    protected function __construct(
        MachineDefinition $definition,
    ) {
        $this->definition = $definition;
    }

    /**
     * Creates a new machine instance with the given definition.
     *
     * This method provides a way to initialize a machine using a specific
     * `MachineDefinition`. It returns a new instance of the `Machine` class,
     * encapsulating the provided definition.
     *
     * @param  MachineDefinition  $definition The definition to initialize the machine with.
     *
     * @return self The newly created machine instance.
     */
    public static function withDefinition(MachineDefinition $definition): self
    {
        return new self($definition);
    }

    // endregion

    // region Machine Definition

    /**
     * Retrieves the machine definition.
     *
     * This method retrieves the machine definition. If the definition is not
     * found, it throws a `MachineDefinitionNotFoundException`.
     *
     * @return MachineDefinition|null The machine definition, or null if not found.
     *
     * @throws MachineDefinitionNotFoundException If the machine definition is not found.
     */
    public static function definition(): ?MachineDefinition
    {
        throw MachineDefinitionNotFoundException::build();
    }

    // endregion

    // region Event Handling

    /**
     * Creates and initializes a new machine instance.
     *
     * This method constructs a new machine instance, initializing it with the
     * provided definition and state. If the definition is `null`, it attempts
     * to retrieve the definition using the `definition()` method.
     *
     * @param  \Tarfinlabs\EventMachine\Definition\MachineDefinition|null  $definition The definition to initialize the machine with.
     * @param  \Tarfinlabs\EventMachine\Actor\State|string|null  $state The initial state of the machine.
     *
     * @return self The newly created and initialized machine instance.
     */
    public static function create(
        MachineDefinition|array $definition = null,
        State|string $state = null,
    ): self {
        if (is_array($definition)) {
            $definition = MachineDefinition::define($definition);
        }

        $machine = new self(definition: $definition ?? static::definition());

        $machine->start($state);

        return $machine;
    }

    /**
     * Starts the machine with the specified state.
     *
     * This method starts the machine with the given state. If no state is provided,
     * it uses the machine's initial state. If a string is provided, it restores
     * the state using the `restoreStateFromRootEventId()` method.
     *
     * @param  \Tarfinlabs\EventMachine\Actor\State|string|null  $state The initial state or root event identifier.
     *
     * @return self The started machine instance.
     */
    public function start(State|string $state = null): self
    {
        $this->state = match (true) {
            $state === null         => $this->definition->getInitialState(),
            $state instanceof State => $state,
            is_string($state)       => $this->restoreStateFromRootEventId($state),
        };

        return $this;
    }

    /**
     * Sends an event to the machine and updates its state.
     *
     * This method transitions the machine's state based on the given event. It
     * updates the machine's state and handles validation guards. If the event
     * should be persisted, it calls the `persist()` method.
     *
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior|array|string  $event The event to send to the machine.
     * @param  bool  $shouldPersist Whether to persist the state change.
     *
     * @return State The updated state of the machine.
     */
    public function send(
        EventBehavior|array|string $event,
        bool $shouldPersist = true,
    ): State {
        $lastPreviousEventNumber = $this->state !== null
            ? $this->state->history->last()->sequence_number
            : 0;

        // If the event is a string, we assume it's the event type.
        if (is_string($event)) {
            $event = ['type' => $event];
        }

        $this->state = $this->definition->transition($event, $this->state);

        if ($shouldPersist === true) {
            $this->persist();
        }

        $this->handleValidationGuards($lastPreviousEventNumber);

        return $this->state;
    }

    // endregion

    // region Recording State

    /**
     * Persists the machine's state.
     *
     * This method upserts the machine's state history into the database. It returns
     * the current state of the machine after the persistence operation.
     *
     * @return ?State The current state of the machine.
     */
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
     * This method queries the machine events based on the provided root event
     * identifier. It reconstructs the state of the machine from the queried
     * events and returns the restored state.
     *
     * @param  string  $key The root event identifier to restore state from.
     *
     * @return State The restored state of the machine.
     *
     * @throws RestoringStateException If the machine state is not found.
     */
    public function restoreStateFromRootEventId(string $key): State
    {
        $machineEvents = MachineEvent::query()
            ->where('root_event_id', $key)
            ->oldest('sequence_number')
            ->get();

        if ($machineEvents->isEmpty()) {
            throw RestoringStateException::build('Machine state is not found.');
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
     * This method restores the context manager instance based on the persisted
     * context data. It utilizes the behavior configuration of the machine's
     * definition or defaults to the `ContextManager` class.
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
     * This method retrieves the current state definition from the machine's
     * definition ID map using the provided machine value.
     *
     * @param  array  $machineValue The machine value containing the ID of the state definition.
     *
     * @return StateDefinition The restored current state definition.
     */
    protected function restoreCurrentStateDefinition(array $machineValue): StateDefinition
    {
        return $this->definition->idMap[$machineValue[0]];
    }

    /**
     * Restores the current event behavior based on the given MachineEvent.
     *
     * This method restores the EventBehavior object based on the provided
     * MachineEvent. It determines the source type and constructs the EventBehavior
     * object accordingly.
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
     *
     * This method processes the machine's validation guards and checks for any
     * failures. If any guard fails, it constructs and throws a
     * `MachineValidationException` with detailed error messages.
     *
     * @param  int  $lastPreviousEventNumber The last previous event sequence number.
     *
     * @throws MachineValidationException If any validation guards fail.
     */
    protected function handleValidationGuards(int $lastPreviousEventNumber): void
    {
        $machineId = $this->state->currentStateDefinition->machine->id;

        $failedGuardEvents = $this
            ->state
            ->history
            ->filter(fn (MachineEvent $machineEvent) => $machineEvent->sequence_number > $lastPreviousEventNumber)
            ->filter(fn (MachineEvent $machineEvent) => preg_match("/{$machineId}\.guard\..*\.fail/", $machineEvent->type))
            ->filter(function (MachineEvent $machineEvent) {
                $failedGuardType  = explode('.', $machineEvent->type)[2];
                $failedGuardClass = $this->definition->behavior[BehaviorType::Guard->value][$failedGuardType];

                return is_subclass_of($failedGuardClass, ValidationGuardBehavior::class);
            });

        if ($failedGuardEvents->isNotEmpty()) {
            $errorsWithMessage = [];

            foreach ($failedGuardEvents as $failedGuardEvent) {
                $errorsWithMessage[$failedGuardEvent->type] = $failedGuardEvent->payload[$failedGuardEvent->type];
            }

            throw MachineValidationException::withMessages($errorsWithMessage);
        }
    }

    // endregion

    // region Interface Implementations

    /**
     * Get the name of the caster class to use when casting from/to this cast target.
     *
     * This method returns the class name of the caster to be used for casting
     * operations. In this case, it returns the `MachineCast` class.
     *
     * @param  array<mixed>  $arguments
     *
     * @return string The class name of the caster.
     */
    public static function castUsing(array $arguments): string
    {
        return MachineCast::class;
    }

    /**
     * Returns the JSON serialized representation of the object.
     *
     * This method returns the JSON serialized representation of the machine object,
     * specifically the root event ID from the state's history.
     *
     * @return string The JSON serialized representation of the object.
     */
    public function jsonSerialize(): string
    {
        return $this->state->history->first()->root_event_id;
    }

    /**
     * Returns a string representation of the current object.
     *
     * This method returns a string representation of the machine object,
     * specifically the root event ID from the state's history or an empty string.
     *
     * @return string The string representation of the object.
     */
    public function __toString(): string
    {
        return $this->state->history->first()->root_event_id ?? '';
    }

    // endregion
}

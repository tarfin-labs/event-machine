<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Exception;
use Stringable;
use JsonSerializable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Casts\MachineCast;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Traits\ResolvesBehaviors;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Exceptions\MachineAlreadyRunningException;
use Tarfinlabs\EventMachine\Exceptions\MachineDefinitionNotFoundException;

class Machine implements Castable, JsonSerializable, Stringable
{
    use ResolvesBehaviors;

    // region Fields

    /** The machine definition that this machine is based on */
    public ?MachineDefinition $definition = null;

    /** The current state of the machine */
    public ?State $state = null;

    // endregion

    // region Constructors

    /**
     * Constructor for the given class.
     *
     * This method is used to initialize an instance of the class.
     * It takes a `MachineDefinition` object as a parameter and
     * assigns it to the `$definition` property of the instance.
     *
     * @param  MachineDefinition  $definition  The machine definition object.
     */
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
     * @param  MachineDefinition  $definition  The definition to initialize the machine with.
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
     * @param  \Tarfinlabs\EventMachine\Definition\MachineDefinition|array|null  $definition  The definition to initialize the machine with.
     * @param  \Tarfinlabs\EventMachine\Actor\State|string|null  $state  The initial state of the machine.
     *
     * @return self The newly created and initialized machine instance.
     */
    public static function create(
        MachineDefinition|array|null $definition = null,
        State|string|null $state = null,
    ): self {
        if (is_array($definition)) {
            $definition = MachineDefinition::define(
                config: $definition['config'] ?? null,
                behavior: $definition['behavior'] ?? null,
            );
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
     * @param  \Tarfinlabs\EventMachine\Actor\State|string|null  $state  The initial state or root event identifier.
     *
     * @return self The started machine instance.
     */
    public function start(State|string|null $state = null): self
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
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior|array|string  $event  The event to send to the machine.
     *
     * @return State The updated state of the machine.
     *
     * @throws Exception
     */
    public function send(
        EventBehavior|array|string $event,
    ): State {
        if ($this->state instanceof State) {
            $lock = Cache::lock('mre:'.$this->state->history->first()->root_event_id, 60);
        }

        if (isset($lock) && !$lock->get()) {
            throw MachineAlreadyRunningException::build($this->state->history->first()->root_event_id);
        }

        try {
            $lastPreviousEventNumber = $this->state instanceof State
                ? $this->state->history->last()->sequence_number
                : 0;

            // If the event is a string, we assume it's the event type.
            if (is_string($event)) {
                $event = ['type' => $event];
            }

            $this->state = match (true) {
                $event->isTransactional ?? false => DB::transaction(fn (): State => $this->definition->transition($event, $this->state)),
                default                          => $this->definition->transition($event, $this->state)
            };

            if ($this->definition->shouldPersist) {
                $this->persist();
            }

            $this->handleValidationGuards($lastPreviousEventNumber);
        } catch (Exception $exception) {
            throw $exception;
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }

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
        // Retrieve the previous context from the definition's config, or set it to an empty array if not set.
        $incrementalContext = $this->definition->initializeContextFromState()->toArray();

        // Get the last event from the state's history.
        $lastHistoryEvent = $this->state->history->last();

        MachineEvent::upsert(
            values: $this->state->history->map(function (MachineEvent $machineEvent, int $index) use (&$incrementalContext, $lastHistoryEvent): array {
                // Get the context of the current machine event.
                $changes = $machineEvent->context;

                // If the current machine event is not the last one, compare its context with the incremental context and get the differences.
                if ($machineEvent->id !== $lastHistoryEvent->id && $index > 0) {
                    $changes = $this->arrayRecursiveDiff($changes, $incrementalContext);
                }

                // If there are changes, update the incremental context to the current event's context.
                if (!empty($changes)) {
                    $incrementalContext = $this->arrayRecursiveMerge($incrementalContext, $machineEvent->context);
                }

                $machineEvent->context = $changes;

                return array_merge($machineEvent->toArray(), [
                    'created_at'    => $machineEvent->created_at->toDateTimeString(),
                    'machine_value' => json_encode($machineEvent->machine_value, JSON_THROW_ON_ERROR),
                    'payload'       => json_encode($machineEvent->payload, JSON_THROW_ON_ERROR),
                    'context'       => json_encode($machineEvent->context, JSON_THROW_ON_ERROR),
                    'meta'          => json_encode($machineEvent->meta, JSON_THROW_ON_ERROR),
                ]);
            })->all(),
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
     * @param  string  $key  The root event identifier to restore state from.
     *
     * @return State The restored state of the machine.
     *
     * @throws RestoringStateException If the machine state is not found.
     */
    public function restoreStateFromRootEventId(string $key): State
    {
        // First, try to find events in the active table
        $machineEvents = MachineEvent::query()
            ->where('root_event_id', $key)
            ->oldest('sequence_number')
            ->get();

        // If not found in active table, check archive
        if ($machineEvents->isEmpty()) {
            $machineEvents = $this->restoreFromArchive($key);
        }

        if ($machineEvents->isEmpty()) {
            throw RestoringStateException::build('Machine state is not found.');
        }

        $lastMachineEvent = $machineEvents->last();

        $state = new State(
            context: $this->restoreContext($lastMachineEvent->context),
            currentStateDefinition: $this->restoreCurrentStateDefinition($lastMachineEvent->machine_value),
            currentEventBehavior: $this->restoreCurrentEventBehavior($lastMachineEvent),
            history: $machineEvents,
        );

        // For parallel states, restore the actual multi-value state
        if (count($lastMachineEvent->machine_value) > 1) {
            $state->setValues($lastMachineEvent->machine_value);
        }

        return $state;
    }

    /**
     * Restores machine events from the archive table.
     *
     * This method looks up the archived events by root_event_id, decompresses them,
     * and returns them as an EventCollection for transparent operation.
     *
     * @param  string  $rootEventId  The root event identifier.
     *
     * @return EventCollection The restored machine events.
     */
    protected function restoreFromArchive(string $rootEventId): EventCollection
    {
        $archiveService = new ArchiveService();
        $events         = $archiveService->restoreMachine($rootEventId, true);

        return $events ?? new EventCollection([]);
    }

    /**
     * Restores the context using the persisted context data.
     *
     * This method restores the context manager instance based on the persisted
     * context data. It utilizes the behavior configuration of the machine's
     * definition or defaults to the `ContextManager` class.
     *
     * @param  array  $persistedContext  The persisted context data.
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
     * For parallel states (multiple values), finds the common parallel ancestor.
     * For non-parallel states (single value), returns the state definition directly.
     *
     * @param  array  $machineValue  The machine value containing the ID(s) of the state definition(s).
     *
     * @return StateDefinition The restored current state definition.
     */
    protected function restoreCurrentStateDefinition(array $machineValue): StateDefinition
    {
        // Single value - non-parallel state
        if (count($machineValue) === 1) {
            return $this->definition->idMap[$machineValue[0]];
        }

        // Multiple values - parallel state, find common parallel ancestor
        return $this->findCommonParallelAncestor($machineValue);
    }

    /**
     * Find the common parallel ancestor for multiple active states.
     *
     * @param  array  $machineValue  Array of active state IDs.
     *
     * @return StateDefinition The common parallel ancestor.
     */
    protected function findCommonParallelAncestor(array $machineValue): StateDefinition
    {
        if (count($machineValue) === 0) {
            return $this->definition->root;
        }

        // Get the first state and find its parallel ancestor
        $firstState = $this->definition->idMap[$machineValue[0]] ?? null;

        if ($firstState === null) {
            return $this->definition->root;
        }

        // Walk up the tree to find a parallel ancestor
        $current = $firstState->parent;

        while ($current !== null) {
            if ($current->type === StateDefinitionType::PARALLEL) {
                // Verify all machine values are descendants of this parallel state
                $allDescendants = true;

                foreach ($machineValue as $stateId) {
                    if (!str_starts_with($stateId, $current->id)) {
                        $allDescendants = false;

                        break;
                    }
                }

                if ($allDescendants) {
                    return $current;
                }
            }

            $current = $current->parent;
        }

        // Fallback to root if no common parallel ancestor found
        return $this->definition->root;
    }

    /**
     * Restores the current event behavior based on the given MachineEvent.
     *
     * This method restores the EventBehavior object based on the provided
     * MachineEvent. It determines the source type and constructs the EventBehavior
     * object accordingly.
     *
     * @param  MachineEvent  $machineEvent  The MachineEvent object representing the event.
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
     * @param  int  $lastPreviousEventNumber  The last previous event sequence number.
     *
     * @throws MachineValidationException If any validation guards fail.
     */
    protected function handleValidationGuards(int $lastPreviousEventNumber): void
    {
        $machineId = $this->state->currentStateDefinition->machine->id;

        $failedGuardEvents = $this
            ->state
            ->history
            ->filter(fn (MachineEvent $machineEvent): bool => $machineEvent->sequence_number > $lastPreviousEventNumber)
            ->filter(fn (MachineEvent $machineEvent): int|false => preg_match("/{$machineId}\.guard\..*\.fail/", $machineEvent->type))
            ->filter(function (MachineEvent $machineEvent): bool {
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
        return (string) ($this->state->history->first()->root_event_id ?? '');
    }

    // endregion

    /**
     * Retrieves the result of the state machine.
     *
     * This method returns the result of the state machine execution.
     *
     * If the current state is a final state and a result behavior is
     * defined for that state, it applies the result behavior and
     * returns the result. Otherwise, it returns null.
     *
     * @return mixed The result of the state machine.
     */
    public function result(): mixed
    {
        $currentStateDefinition = $this->state->currentStateDefinition;
        $behaviorDefinition     = $this->definition->behavior[BehaviorType::Result->value];

        if ($currentStateDefinition->type !== StateDefinitionType::FINAL) {
            return null;
        }

        $id = $currentStateDefinition->id;
        if (!isset($behaviorDefinition[$id])) {
            return null;
        }

        $resultBehavior = $behaviorDefinition[$id];
        if (!is_callable($resultBehavior)) {
            // If the result behavior contains a colon, it means that it has a parameter.
            if (str_contains((string) $resultBehavior, ':')) {
                [$resultBehavior, $arguments] = explode(':', (string) $resultBehavior);
            }

            $resultBehavior = new $resultBehavior();
        }

        /* @var callable $resultBehavior */
        return $resultBehavior(
            $this->state->context,
            $this->state->currentEventBehavior,
            $arguments ?? null,
        );
    }

    // region Private Methods
    /**
     * Compares two arrays recursively and returns the difference.
     */
    private function arrayRecursiveDiff(array $array1, array $array2): array
    {
        $difference = [];
        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    $difference[$key] = $value;
                } else {
                    $new_diff = $this->arrayRecursiveDiff($value, $array2[$key]);
                    if ($new_diff !== []) {
                        $difference[$key] = $new_diff;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $difference[$key] = $value;
            }
        }

        return $difference;
    }

    /**
     * Merges two arrays recursively.
     */
    protected function arrayRecursiveMerge(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayRecursiveMerge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
    // endregion
}

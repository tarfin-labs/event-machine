<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Stringable;
use JsonSerializable;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Context;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Casts\MachineCast;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Support\ArrayUtils;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Traits\ResolvesBehaviors;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionTimeoutJob;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Exceptions\MachineLockTimeoutException;
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

    /** Whether parallel region jobs were dispatched to the queue in this lifecycle */
    public bool $dispatched = false;

    /** @var array<class-string, array{result: mixed, fail: bool, error: ?string, finalState: ?string, invocations: list<array>, creations: list<array>, sends: list<array>}> Machine-level fakes for testing. */
    private static array $machineFakes = [];

    /** Whether this instance was created via createFaked() — send/persist become no-ops. */
    protected bool $isFakedInstance = false;

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
     * @param  MachineDefinition|array|null  $definition  The definition to initialize the machine with.
     * @param  State|string|null  $state  The initial state of the machine.
     *
     * @return self The newly created and initialized machine instance.
     */
    public static function create(
        MachineDefinition|array|null $definition = null,
        State|string|null $state = null,
    ): self {
        // Faked: return stub without DB restore or start()
        if (static::isMachineFaked()) {
            return static::createFaked();
        }

        if (is_array($definition)) {
            $definition = MachineDefinition::define(
                config: $definition['config'] ?? null,
                behavior: $definition['behavior'] ?? null,
            );
        }

        $machine                           = new self(definition: $definition ?? static::definition());
        $machine->definition->machineClass = static::class;

        $machine->start($state);

        return $machine;
    }

    /**
     * Create a faked machine stub — no DB restore, no start().
     *
     * send() and persist() become no-ops on the returned instance.
     */
    protected static function createFaked(): self
    {
        $fake = self::getMachineFake(static::class);

        $machine                            = self::withDefinition(static::definition());
        $machine->definition->machineClass  = static::class;
        $machine->definition->shouldPersist = false;
        $machine->isFakedInstance           = true;

        $machine->state = new State(
            context: new ContextManager(data: $fake['result'] ?? []),
            currentStateDefinition: null,
        );

        self::$machineFakes[static::class]['creations'][] = [];

        return $machine;
    }

    /**
     * Create a TestMachine for fluent testing.
     *
     * Context is merged BEFORE initialization — entry actions see it.
     * Guards and faking are applied before getInitialState() runs,
     * solving @always timing issues.
     *
     * @param  array  $context  Context values to inject before machine start.
     * @param  array<class-string, mixed>  $guards  Guard class => return value pairs (pre-init).
     * @param  array<class-string>  $faking  Behavior classes to spy before init.
     */
    public static function test(array $context = [], array $guards = [], array $faking = []): TestMachine
    {
        return TestMachine::withContext(static::class, $context, $guards, $faking);
    }

    /**
     * Create a TestMachine at a specific state without running lifecycle.
     *
     * No entry actions, no @always, no job dispatch.
     * Uses the real definition — all transitions, guards, and actions available.
     *
     * @param  string  $stateId  The state to start at (resolved from idMap).
     * @param  array  $context  Context values to inject.
     * @param  array<class-string, mixed>  $guards  Guard class => return value pairs.
     * @param  array<class-string>  $faking  Behavior classes to spy.
     */
    public static function startingAt(string $stateId, array $context = [], array $guards = [], array $faking = []): TestMachine
    {
        return TestMachine::startingAt(static::class, $stateId, $context, $guards, $faking);
    }

    /**
     * Starts the machine with the specified state.
     *
     * This method starts the machine with the given state. If no state is provided,
     * it uses the machine's initial state. If a string is provided, it restores
     * the state using the `restoreStateFromRootEventId()` method.
     *
     * @param  State|string|null  $state  The initial state or root event identifier.
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

        if ($this->state instanceof State && $this->state->history?->first() !== null) {
            $rootEventId                   = $this->state->history->first()->root_event_id;
            $this->definition->rootEventId = $rootEventId;

            // Ensure machine identity is set on context (survives restore from DB)
            if ($this->state->context->machineId() === null) {
                $this->state->context->setMachineIdentity($rootEventId);
            }
        }

        return $this;
    }

    /**
     * Sends an event to the machine and updates its state.
     *
     * This method transitions the machine's state based on the given event. It
     * updates the machine's state and handles validation guards. If the event
     * should be persisted, it calls the `persist()` method.
     *
     * @param  EventBehavior|array|string  $event  The event to send to the machine.
     *
     * @return State The updated state of the machine.
     *
     * @throws MachineAlreadyRunningException
     */
    public function send(
        EventBehavior|array|string $event,
    ): State {
        if ($this->isFakedInstance) {
            $eventType = match (true) {
                is_array($event)                => $event['type'] ?? null,
                $event instanceof EventBehavior => $event->type,
                default                         => $event,
            };

            self::$machineFakes[$this->definition->machineClass]['sends'][] = [
                'type' => $eventType,
            ];

            return $this->state;
        }

        $lockHandle = null;

        if ($this->state instanceof State && config('machine.parallel_dispatch.enabled', false)) {
            $rootEventId = $this->state->history->first()->root_event_id;

            // Reload from DB — local state may be stale after previous sync dispatch.
            try {
                $this->state = $this->restoreStateFromRootEventId($rootEventId);
            } catch (\Throwable) {
                // Defensive: if reload fails, continue with current local state.
            }

            try {
                $lockHandle = MachineLockManager::acquire(
                    rootEventId: $rootEventId,
                    timeout: 0,
                    ttl: (int) config('machine.parallel_dispatch.lock_ttl', 60),
                    context: 'send',
                );
            } catch (MachineLockTimeoutException) {
                throw MachineAlreadyRunningException::build($rootEventId);
            }
        }

        $shouldDispatch = false;

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

            $shouldDispatch = true;
        } finally {
            $lockHandle?->release();

            if ($shouldDispatch) {
                $this->dispatchPendingParallelJobs();
            } else {
                $this->definition->pendingParallelDispatches = [];
            }
        }

        return $this->state;
    }

    /**
     * Dispatches pending parallel region jobs after lock release.
     */
    public function dispatchPendingParallelJobs(): void
    {
        if ($this->definition->pendingParallelDispatches === []) {
            return;
        }

        $rootEventId    = $this->state->history->first()->root_event_id;
        $queue          = config('machine.parallel_dispatch.queue');
        $regionTimeout  = (int) config('machine.parallel_dispatch.region_timeout', 0);
        $parallelStates = [];

        foreach ($this->definition->pendingParallelDispatches as $dispatch) {
            $job = new ParallelRegionJob(
                machineClass: $this->definition->machineClass,
                rootEventId: $rootEventId,
                regionId: $dispatch['region_id'],
                initialStateId: $dispatch['initial_state_id'],
                contextAtDispatch: $this->state->context->toArray(),
            );

            if ($queue !== null) {
                $job->onQueue($queue);
            }

            dispatch($job);

            // Track unique parallel state IDs for timeout dispatch
            $region = $this->definition->idMap[$dispatch['region_id']] ?? null;
            if ($region?->parent !== null) {
                $parallelStates[$region->parent->id] = true;
            }
        }

        // Dispatch a single timeout check job per parallel state
        if ($regionTimeout > 0) {
            foreach (array_keys($parallelStates) as $parallelStateId) {
                $timeoutJob = new ParallelRegionTimeoutJob(
                    machineClass: $this->definition->machineClass,
                    rootEventId: $rootEventId,
                    parallelStateId: $parallelStateId,
                );

                if ($queue !== null) {
                    $timeoutJob->onQueue($queue);
                }

                dispatch($timeoutJob)->delay($regionTimeout);
            }
        }

        $this->definition->pendingParallelDispatches = [];
        $this->dispatched                            = true;
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
        if ($this->isFakedInstance) {
            return $this->state;
        }

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
                    $changes = ArrayUtils::recursiveDiff($changes, $incrementalContext);
                }

                // If there are changes, update the incremental context to the current event's context.
                if (!empty($changes)) {
                    $incrementalContext = ArrayUtils::recursiveMerge($incrementalContext, $machineEvent->context);
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

        // Sync machine_current_states table (diff-based: only update changed states)
        $this->syncCurrentStates();

        return $this->state;
    }

    /**
     * Sync the machine_current_states table with the current state value.
     *
     * Diff-based: only adds newly entered states and removes exited states.
     * Unchanged states keep their original state_entered_at timestamp.
     * Self-loops (same state) produce no changes.
     */
    protected function syncCurrentStates(): void
    {
        $rootEventId = $this->state->history->first()?->root_event_id;

        if ($rootEventId === null) {
            return;
        }

        $newStates = $this->state->value ?? [];
        $existing  = MachineCurrentState::forInstance($rootEventId)->pluck('state_id')->toArray();

        $added   = array_diff($newStates, $existing);
        $removed = array_diff($existing, $newStates);

        // Remove states no longer active
        if ($removed !== []) {
            MachineCurrentState::forInstance($rootEventId)
                ->whereIn('state_id', $removed)
                ->delete();
        }

        // Add newly entered states (state_entered_at = now)
        foreach ($added as $stateId) {
            MachineCurrentState::create([
                'root_event_id'    => $rootEventId,
                'machine_class'    => $this->definition->machineClass ?? static::class,
                'state_id'         => $stateId,
                'state_entered_at' => now(),
            ]);
        }

        // Unchanged states are NOT touched → state_entered_at preserved
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

        // Unwrap legacy v8 format: ['data' => [...]] → [...]
        $contextData = (count($persistedContext) === 1 && array_key_exists('data', $persistedContext))
            ? $persistedContext['data']
            : $persistedContext;

        return Context::from($contextData);
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
                    if (!str_starts_with((string) $stateId, $current->id)) {
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

    // region Machine Faking

    /**
     * Register a machine fake to short-circuit child machine execution in tests.
     *
     * When a parent machine delegates to a faked child, the child is never
     * actually created. Instead, the parent immediately routes @done or @fail
     * based on the fake configuration.
     *
     * Works for both sync and async delegation.
     *
     * @param  array|null  $result  The fake result to return via @done.
     * @param  bool  $fail  Whether to trigger @fail instead of @done.
     * @param  string|null  $error  The error message for @fail.
     * @param  string|null  $finalState  The child's final state key — determines which `@done.{state}` route fires on the parent.
     */
    public static function fake(
        ?array $result = null,
        bool $fail = false,
        ?string $error = null,
        ?string $finalState = null,
    ): void {
        self::$machineFakes[static::class] = [
            'result'      => $result,
            'fail'        => $fail,
            'error'       => $error,
            'finalState'  => $finalState,
            'invocations' => [],
            'creations'   => [],
            'sends'       => [],
        ];
    }

    /**
     * Check if a machine class is currently faked.
     */
    public static function isMachineFaked(?string $class = null): bool
    {
        return isset(self::$machineFakes[$class ?? static::class]);
    }

    /**
     * Get the fake configuration for a machine class.
     *
     * @return array{result: mixed, fail: bool, error: ?string, finalState: ?string, invocations: list<array>, creations: list<array>, sends: list<array>}|null
     */
    public static function getMachineFake(?string $class = null): ?array
    {
        return self::$machineFakes[$class ?? static::class] ?? null;
    }

    /**
     * Record a machine invocation for assertion tracking.
     */
    public static function recordMachineInvocation(string $class, array $context): void
    {
        if (isset(self::$machineFakes[$class])) {
            self::$machineFakes[$class]['invocations'][] = $context;
        }
    }

    /**
     * Get recorded invocations for a faked machine.
     *
     * @return list<array>
     */
    public static function getMachineInvocations(?string $class = null): array
    {
        return self::$machineFakes[$class ?? static::class]['invocations'] ?? [];
    }

    /**
     * Assert the machine was invoked as a child at least once.
     */
    public static function assertInvoked(): void
    {
        $invocations = self::getMachineInvocations(static::class);

        if ($invocations === []) {
            throw new AssertionFailedError(
                'Expected machine ['.static::class.'] to be invoked, but it was not.'
            );
        }
    }

    /**
     * Assert the machine was never invoked as a child.
     */
    public static function assertNotInvoked(): void
    {
        $invocations = self::getMachineInvocations(static::class);

        if ($invocations !== []) {
            throw new AssertionFailedError(
                'Expected machine ['.static::class.'] not to be invoked, but it was invoked '.count($invocations).' time(s).'
            );
        }
    }

    /**
     * Assert the machine was invoked exactly N times as a child.
     */
    public static function assertInvokedTimes(int $times): void
    {
        $invocations = self::getMachineInvocations(static::class);
        $actual      = count($invocations);

        if ($actual !== $times) {
            throw new AssertionFailedError(
                'Expected machine ['.static::class."] to be invoked {$times} time(s), but it was invoked {$actual} time(s)."
            );
        }
    }

    /**
     * Assert the machine was invoked with context containing the given subset.
     *
     * Checks that at least one invocation's context contains all key-value
     * pairs from the expected array (subset match, not exact).
     */
    public static function assertInvokedWith(array $expected): void
    {
        $invocations = self::getMachineInvocations(static::class);

        if ($invocations === []) {
            throw new AssertionFailedError(
                'Expected machine ['.static::class.'] to be invoked with '.json_encode($expected).', but it was never invoked.'
            );
        }

        foreach ($invocations as $context) {
            $matched = true;

            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $context) || $context[$key] !== $value) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                return;
            }
        }

        throw new AssertionFailedError(
            'Expected machine ['.static::class.'] to be invoked with '.json_encode($expected).', but no invocation matched. Actual invocations: '.json_encode($invocations)
        );
    }

    /**
     * Assert create() was called on a faked machine at least once.
     */
    public static function assertCreated(): void
    {
        $creations = self::$machineFakes[static::class]['creations'] ?? [];

        if ($creations === []) {
            throw new AssertionFailedError(
                'Expected machine ['.static::class.'] to be created, but it was not.'
            );
        }
    }

    /**
     * Assert create() was NOT called on a faked machine.
     */
    public static function assertNotCreated(): void
    {
        $creations = self::$machineFakes[static::class]['creations'] ?? [];

        if ($creations !== []) {
            throw new AssertionFailedError(
                'Expected machine ['.static::class.'] not to be created, but it was created '.count($creations).' time(s).'
            );
        }
    }

    /**
     * Assert create() was called exactly N times.
     */
    public static function assertCreatedTimes(int $times): void
    {
        $creations = self::$machineFakes[static::class]['creations'] ?? [];
        $actual    = count($creations);

        if ($actual !== $times) {
            throw new AssertionFailedError(
                'Expected machine ['.static::class.'] to be created '.$times.' time(s), but was created '.$actual.' time(s).'
            );
        }
    }

    /**
     * Assert send() was called with the given event type on a faked machine.
     */
    public static function assertSent(string $eventType): void
    {
        $sends = self::$machineFakes[static::class]['sends'] ?? [];

        foreach ($sends as $send) {
            if ($send['type'] === $eventType) {
                return;
            }
        }

        throw new AssertionFailedError(
            'Expected event ['.$eventType.'] to be sent to ['.static::class.'], but it was not.'
        );
    }

    /**
     * Assert send() was NOT called with the given event type.
     */
    public static function assertNotSent(string $eventType): void
    {
        $sends = self::$machineFakes[static::class]['sends'] ?? [];

        foreach ($sends as $send) {
            if ($send['type'] === $eventType) {
                throw new AssertionFailedError(
                    'Expected event ['.$eventType.'] not to be sent to ['.static::class.'], but it was.'
                );
            }
        }
    }

    /**
     * Assert send() was called with the given event type exactly N times.
     */
    public static function assertSentTimes(string $eventType, int $times): void
    {
        $sends = self::$machineFakes[static::class]['sends'] ?? [];
        $count = 0;

        foreach ($sends as $send) {
            if ($send['type'] === $eventType) {
                $count++;
            }
        }

        if ($count !== $times) {
            throw new AssertionFailedError(
                'Expected event ['.$eventType.'] to be sent to ['.static::class.'] '.$times.' time(s), but was sent '.$count.' time(s).'
            );
        }
    }

    /**
     * Reset all machine fakes.
     */
    public static function resetMachineFakes(): void
    {
        self::$machineFakes = [];
    }

    /**
     * Reset the fake for a single machine class.
     */
    public static function resetMachineFake(string $class): void
    {
        unset(self::$machineFakes[$class]);
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
     * Get the events that can currently be sent to this machine.
     *
     * Convenience proxy to $this->state->availableEvents().
     *
     * @return array<int, array{type: string, source: string, region?: string}>
     */
    public function availableEvents(): array
    {
        return $this->state->availableEvents();
    }

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
        $arguments      = null;

        if (!is_callable($resultBehavior)) {
            if (str_contains((string) $resultBehavior, ':')) {
                [$resultBehavior, $arguments] = explode(':', (string) $resultBehavior);
                $arguments                    = explode(',', $arguments);
            }

            $resultBehavior = resolve($resultBehavior);
        }

        $params = InvokableBehavior::injectInvokableBehaviorParameters(
            actionBehavior: $resultBehavior,
            state: $this->state,
            eventBehavior: $this->state->triggeringEvent ?? $this->state->currentEventBehavior,
            actionArguments: $arguments,
        );

        return $resultBehavior(...$params);
    }

    // region Private Methods
    // endregion
}

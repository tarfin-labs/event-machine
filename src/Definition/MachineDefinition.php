<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Mockery\MockInterface;
use Spatie\LaravelData\Optional;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Jobs\ChildJobJob;
use Tarfinlabs\EventMachine\Jobs\ListenerJob;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\StateConfigValidator;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Routing\EndpointDefinition;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Jobs\ChildMachineTimeoutJob;
use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Routing\ForwardedEndpointDefinition;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;
use Tarfinlabs\EventMachine\Exceptions\InvalidEndpointDefinitionException;
use Tarfinlabs\EventMachine\Exceptions\InvalidScheduleDefinitionException;
use Tarfinlabs\EventMachine\Exceptions\MaxTransitionDepthExceededException;
use Tarfinlabs\EventMachine\Exceptions\InvalidFinalStateDefinitionException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;
use Tarfinlabs\EventMachine\Exceptions\InvalidParallelStateDefinitionException;

class MachineDefinition
{
    // region Public Properties

    /** The default id for the root machine definition. */
    public const DEFAULT_ID = 'machine';

    /** The default delimiter used for constructing the global id by concatenating state definition local IDs. */
    public const STATE_DELIMITER = '.';

    /** The maximum recursive transition depth allowed within a single macrostep (Rhapsody default). */
    public const DEFAULT_MAX_TRANSITION_DEPTH = 100;

    /** The root state definition for this machine definition. */
    public StateDefinition $root;

    /**
     * Parsed listener definitions from the 'listen' config key.
     *
     * @var array{entry: list<array{action: string, queue: bool}>, exit: list<array{action: string, queue: bool}>, transition: list<array{action: string, queue: bool}>}
     */
    public array $listen = [
        'entry'      => [],
        'exit'       => [],
        'transition' => [],
    ];

    /**
     * The map of state definitions to their ids.
     *
     * @var array<StateDefinition>
     */
    public array $idMap = [];

    /**
     * The child state definitions of this state definition.
     *
     * @var array<StateDefinition>|null
     */
    public ?array $stateDefinitions = null;

    /**
     * The events that can be accepted by this machine definition.
     *
     * @var null|array<string>
     */
    public ?array $events = null;

    /** Represents a queue for storing events that raised during the execution of the transition. */
    public Collection $eventQueue;

    /** The initial state definition for this machine definition. */
    public ?StateDefinition $initialStateDefinition = null;

    /** Indicates whether the scenario is enabled. */
    public bool $scenariosEnabled = false;

    /** machine-based variable that determines whether to persist the state change. */
    public bool $shouldPersist = true;

    /** Machine class name for job reconstruction (set by Machine::start). */
    public ?string $machineClass = null;

    /** Root event ID for state restoration (set by Machine::start). */
    public ?string $rootEventId = null;

    /** Pending parallel region dispatches (consumed by Machine::send after persist). */
    public array $pendingParallelDispatches = [];

    /** @var array<string, EndpointDefinition>|null Parsed endpoint definitions. */
    public ?array $parsedEndpoints = null;

    /** @var array<string, ForwardedEndpointDefinition>|null Parsed forwarded endpoint definitions keyed by parent event type. */
    public ?array $forwardedEndpoints = null;

    /** @var array<string, ScheduleDefinition>|null Parsed schedule definitions keyed by resolved event type. */
    public ?array $parsedSchedules = null;

    // endregion

    // region Constructor

    /**
     * Create a new machine definition with the given arguments.
     *
     * @param  array|null  $config  The raw configuration array used to create the machine definition.
     * @param  array|null  $behavior  The implementation of the machine behavior that defined in the machine definition.
     * @param  string  $id  The id of the machine.
     * @param  string|null  $version  The version of the machine.
     * @param  string  $delimiter  The string delimiter for serializing the path to a string.
     */
    private function __construct(
        public ?array $config,
        public ?array $behavior,
        public string $id,
        public ?string $version,
        public ?array $scenarios,
        private readonly ?array $endpoints = null,
        private readonly ?array $schedules = null,
        public string $delimiter = self::STATE_DELIMITER,
    ) {
        StateConfigValidator::validate($config);

        $this->scenariosEnabled = isset($this->config['scenarios_enabled']) && $this->config['scenarios_enabled'] === true;

        $this->shouldPersist = $this->config['should_persist'] ?? $this->shouldPersist;

        try {
            $parallelDispatchEnabled = config('machine.parallel_dispatch.enabled', false);
        } catch (\Throwable) {
            $parallelDispatchEnabled = false;
        }

        if ($parallelDispatchEnabled) {
            $this->validateParallelDispatchConfig();
        }

        $this->root = $this->createRootStateDefinition($config);

        $this->parseListenConfig($config);

        // Checks if the scenario is enabled, and if true, creates scenario state definitions.
        if ($this->scenariosEnabled) {
            $this->createScenarioStateDefinitions();
        }

        $this->root->initializeTransitions();

        $this->stateDefinitions = $this->root->stateDefinitions;
        $this->events           = $this->root->events;

        $this->checkFinalStatesForTransitions();

        $this->eventQueue = new Collection();

        $this->initialStateDefinition = $this->root->initialStateDefinition;

        $this->setupContextManager();

        if ($this->endpoints !== null) {
            $this->parseEndpoints();
        }

        $this->parseForwardedEndpoints();

        if ($this->schedules !== null) {
            $this->parseSchedules();
        }
    }

    private function validateParallelDispatchConfig(): void
    {
        if (!$this->shouldPersist) {
            throw InvalidParallelStateDefinitionException::requiresPersistence();
        }
    }

    /**
     * Parse and validate endpoint definitions.
     *
     * Converts raw endpoint config arrays into EndpointDefinition value objects
     * and validates that referenced events, results, and actions exist.
     */
    private function parseEndpoints(): void
    {
        $this->parsedEndpoints = [];

        foreach ($this->endpoints as $key => $config) {
            // List syntax: ['SUBMIT'] or [SubmitEvent::class]
            if (is_int($key)) {
                $key    = $config;
                $config = null;
            }

            $endpoint = EndpointDefinition::fromConfig($key, $config);

            if ($this->events === null || !in_array($endpoint->eventType, $this->events, true)) {
                throw InvalidEndpointDefinitionException::undefinedEvent($endpoint->eventType);
            }

            if (
                $endpoint->resultBehavior !== null
                && !class_exists($endpoint->resultBehavior)
                && !isset($this->behavior['results'][$endpoint->resultBehavior])
            ) {
                throw InvalidEndpointDefinitionException::undefinedResult($endpoint->resultBehavior);
            }

            if (
                $endpoint->actionClass !== null
                && !is_subclass_of($endpoint->actionClass, MachineEndpointAction::class)
            ) {
                throw InvalidEndpointDefinitionException::invalidAction($endpoint->actionClass);
            }

            $this->parsedEndpoints[$endpoint->eventType] = $endpoint;
        }
    }

    /**
     * Parse forwarded endpoints from states with `forward` config.
     *
     * Iterates all states with machineInvokeDefinition.forward, loads the child
     * definition, resolves forward entries into ForwardedEndpointDefinition objects.
     * Validates: child has the event types, no overlap with parent endpoints/behavior.
     */
    private function parseForwardedEndpoints(): void
    {
        $this->forwardedEndpoints = [];

        foreach ($this->idMap as $stateDefinition) {
            if (!$stateDefinition->hasMachineInvoke()) {
                continue;
            }

            $invokeDefinition = $stateDefinition->getMachineInvokeDefinition();
            if (!$invokeDefinition->hasForward()) {
                continue;
            }
            if ($invokeDefinition->machineClass === '') {
                continue;
            }

            /** @var MachineDefinition $childDefinition */
            $childDefinition = $invokeDefinition->machineClass::definition();

            $resolved = $invokeDefinition->resolveForwardEndpoints($childDefinition);

            foreach ($resolved as $parentEventType => $fwdEndpoint) {
                // If child doesn't declare EventBehavior class for this event,
                // forward still works via Machine::send() internally but cannot
                // be auto-registered as an HTTP endpoint.
                if ($fwdEndpoint->childEventClass === '') {
                    continue;
                }

                // Validate: no overlap with parent's explicit endpoints
                if ($this->parsedEndpoints !== null && isset($this->parsedEndpoints[$parentEventType])) {
                    throw new \InvalidArgumentException(
                        "State '{$stateDefinition->id}' forwards '{$parentEventType}' which is also declared in parent's "
                        ."endpoints. Remove '{$parentEventType}' from endpoints — forward is the single source of truth for child events."
                    );
                }

                // Validate: no overlap with parent's behavior events
                if (isset($this->behavior['events'][$parentEventType])) {
                    throw new \InvalidArgumentException(
                        "State '{$stateDefinition->id}' forwards '{$parentEventType}' which is also declared in parent's "
                        ."behavior.events. Remove '{$parentEventType}' from behavior.events — forward auto-discovers child events."
                    );
                }

                // Validate: no collision with another state's forward
                if (isset($this->forwardedEndpoints[$parentEventType])) {
                    throw new \InvalidArgumentException(
                        "Forward event '{$parentEventType}' is declared in multiple delegating states. "
                        .'Use rename syntax to disambiguate (e.g., \'CANCEL_PAYMENT\' => \'CANCEL\').'
                    );
                }

                $this->forwardedEndpoints[$parentEventType] = $fwdEndpoint;
            }
        }
    }

    /**
     * Parse and normalize schedule definitions.
     *
     * Converts raw schedule config into ScheduleDefinition value objects
     * keyed by resolved event type string (FQCN keys are normalized).
     * Validates that each scheduled event type exists in the machine's event list.
     */
    private function parseSchedules(): void
    {
        $this->parsedSchedules = [];

        foreach ($this->schedules ?? [] as $key => $resolver) {
            $schedule = ScheduleDefinition::fromConfig((string) $key, $resolver);

            if ($this->events === null || !in_array($schedule->eventType, $this->events, true)) {
                throw InvalidScheduleDefinitionException::undefinedEvent($schedule->eventType);
            }

            $this->parsedSchedules[$schedule->eventType] = $schedule;
        }
    }

    // endregion

    // region Static Constructors

    /**
     * Define a new machine with the given configuration and behavior.
     *
     * @param  ?array  $config  The raw configuration array used to create the machine.
     * @param  array|null  $behavior  An array of behavior options.
     *
     * @return self The created machine definition.
     */
    public static function define(
        ?array $config = null,
        ?array $behavior = null,
        ?array $scenarios = null,
        ?array $endpoints = null,
        ?array $schedules = null,
    ): self {
        return new self(
            config: $config ?? null,
            behavior: array_merge(self::initializeEmptyBehavior(), $behavior ?? []),
            id: $config['id'] ?? self::DEFAULT_ID,
            version: $config['version'] ?? null,
            scenarios: $scenarios,
            endpoints: $endpoints,
            schedules: $schedules,
            delimiter: $config['delimiter'] ?? self::STATE_DELIMITER,
        );
    }

    // endregion

    // region Protected Methods

    /**
     * Initializes an empty behavior array with empty events, actions and guard arrays.
     *
     * @return array An empty behavior array with empty events, actions and guard arrays.
     */
    protected static function initializeEmptyBehavior(): array
    {
        $behaviorArray = [];

        foreach (BehaviorType::cases() as $behaviorType) {
            $behaviorArray[$behaviorType->value] = [];
        }

        return $behaviorArray;
    }

    /**
     * Create the root state definition.
     *
     * Creates and returns a new instance of `StateDefinition` with the given configuration.
     * If no configuration is provided, the configuration will be set to null.
     * The $options parameter is set with the current `Machine` and machine id.
     *
     * @param  array|null  $config  The configuration for the root state definition.
     *
     * @return StateDefinition The created root state definition.
     */
    protected function createRootStateDefinition(?array $config): StateDefinition
    {
        return new StateDefinition(
            config: $config ?? null,
            options: [
                'machine' => $this,
                'key'     => $this->id,
            ]
        );
    }

    /**
     * Creates scenario state definitions based on the defined scenarios.
     *
     * This method iterates through the specified scenarios and creates StateDefinition objects
     * for each, with the provided states configuration.
     */
    protected function createScenarioStateDefinitions(): void
    {
        if ($this->scenarios !== null && $this->scenarios !== []) {
            foreach ($this->scenarios as $name => $scenarios) {
                $parentStateDefinition = reset($this->idMap);
                $state                 = new StateDefinition(
                    config: ['states' => $scenarios],
                    options: [
                        'parent'  => $parentStateDefinition,
                        'machine' => $this,
                        'key'     => $name,
                    ]
                );

                $state->initializeTransitions();
            }
        }
    }

    /**
     * Build the initial state for the machine.
     *
     * For parallel states, enters all regions simultaneously.
     *
     * @return ?State The initial state of the machine.
     */
    public function getInitialState(EventBehavior|array|null $event = null): ?State
    {
        if (is_null($this->initialStateDefinition)) {
            return null;
        }

        $context = $this->initializeContextFromState();

        $initialState = $this->buildCurrentState(
            context: $context,
            currentStateDefinition: $this->initialStateDefinition,
        );

        $initialState                 = $this->getScenarioStateIfAvailable(state: $initialState, eventBehavior: $event ?? null);
        $this->initialStateDefinition = $initialState->currentStateDefinition;

        // Record the internal machine init event.
        $initialState->setInternalEventBehavior(type: InternalEvent::MACHINE_START);

        // Set machine identity (separate from context data — never pollutes the data array).
        // Available before entry actions run, so behaviors can use $context->machineId().
        $rootEventId = $initialState->history->first()->root_event_id;
        $initialState->context->setMachineIdentity($rootEventId);

        // Run root-level entry actions (machine lifecycle — runs once on init)
        if ($this->root->entry !== []) {
            $this->runRootLifecycleActions(
                actions: $this->root->entry,
                state: $initialState,
                eventBehavior: $initialState->currentEventBehavior,
                startEvent: InternalEvent::MACHINE_ENTRY_START,
                finishEvent: InternalEvent::MACHINE_ENTRY_FINISH,
            );
        }

        // Handle parallel state initialization - enter all regions
        if ($this->initialStateDefinition->type === StateDefinitionType::PARALLEL) {
            $this->enterParallelState($initialState, $this->initialStateDefinition, $initialState->currentEventBehavior);
        } else {
            // Record the internal initial state init event.
            $initialState->setInternalEventBehavior(
                type: InternalEvent::STATE_ENTER,
                placeholder: $initialState->currentStateDefinition->route,
            );

            // Run entry actions on the initial state definition
            $this->initialStateDefinition->runEntryActions(
                state: $initialState,
                eventBehavior: $initialState->currentEventBehavior,
            );

            // Run entry listeners (listen.entry — NOT listen.transition on init)
            $this->runEntryListeners($initialState, $initialState->currentEventBehavior);

            // Handle machine delegation on the initial state
            $this->handleMachineInvoke($initialState, $this->initialStateDefinition, $initialState->currentEventBehavior);

            // Process compound onDone if the initial state is final within a compound parent
            if ($this->initialStateDefinition->type === StateDefinitionType::FINAL) {
                $this->processCompoundOnDone($initialState, $this->initialStateDefinition, $initialState->currentEventBehavior);
            }
        }

        if ($this->initialStateDefinition?->transitionDefinitions !== null) {
            foreach ($this->initialStateDefinition->transitionDefinitions as $transition) {
                if ($transition->isAlways === true) {
                    return $this->transition(
                        event: [
                            'type'  => TransitionProperty::Always->value,
                            'actor' => $initialState->currentEventBehavior->actor($context),
                        ],
                        state: $initialState,
                        recursionDepth: 1,
                    );
                }
            }
        }

        if ($this->eventQueue->isNotEmpty()) {
            $firstEvent = $this->eventQueue->shift();

            $eventBehavior = $this->initializeEvent($firstEvent, $initialState);

            return $this->transition($eventBehavior, $initialState, recursionDepth: 1);
        }

        // Record the machine finish event if the initial state is a final state.
        if ($initialState->currentStateDefinition->type === StateDefinitionType::FINAL) {
            // Run root-level exit actions (machine lifecycle — runs once on completion)
            if ($this->root->exit !== []) {
                $this->runRootLifecycleActions(
                    actions: $this->root->exit,
                    state: $initialState,
                    startEvent: InternalEvent::MACHINE_EXIT_START,
                    finishEvent: InternalEvent::MACHINE_EXIT_FINISH,
                );
            }

            $initialState->setInternalEventBehavior(
                type: InternalEvent::MACHINE_FINISH,
                placeholder: $initialState->currentStateDefinition->route
            );
        }

        return $initialState;
    }

    /**
     * Determine if parallel regions should be dispatched as queue jobs.
     */
    protected function shouldDispatchParallel(StateDefinition $parallelState): bool
    {
        if (!config('machine.parallel_dispatch.enabled', false)) {
            return false;
        }

        if (!$this->shouldPersist) {
            return false;
        }

        if ($this->machineClass === null || $this->machineClass === Machine::class) {
            return false;
        }

        // Count regions with entry actions — need at least 2 for parallelism gain
        $regionsWithEntryActions = 0;
        if ($parallelState->stateDefinitions !== null) {
            foreach ($parallelState->stateDefinitions as $region) {
                $regionInitial = $region->findInitialStateDefinition();
                if ($regionInitial !== null && $regionInitial->entry !== null && $regionInitial->entry !== []) {
                    $regionsWithEntryActions++;
                }
            }
        }

        return $regionsWithEntryActions >= 2;
    }

    protected function enterParallelState(
        State $state,
        StateDefinition $parallelState,
        ?EventBehavior $eventBehavior = null
    ): void {
        // Record entering the parallel state
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTER,
            placeholder: $parallelState->route,
        );

        // Run entry actions on the parallel state itself
        $parallelState->runEntryActions($state, $eventBehavior);

        // Run entry listeners on the parallel state itself
        $this->runEntryListeners($state, $eventBehavior);

        // Collect all initial states from all regions
        $initialStates = $parallelState->findAllInitialStateDefinitions();
        $state->setValues(array_map(fn (StateDefinition $s): string => $s->id, $initialStates));

        // Dispatch mode: queue region entry actions for parallel execution
        if ($this->shouldDispatchParallel($parallelState)) {
            if ($parallelState->stateDefinitions !== null) {
                foreach ($parallelState->stateDefinitions as $region) {
                    $regionInitial = $region->findInitialStateDefinition();

                    if ($regionInitial !== null) {
                        // Mark regions with entry actions for dispatch
                        if ($regionInitial->entry !== null && $regionInitial->entry !== []) {
                            // Region entry event will be recorded by ParallelRegionJob on completion
                            $this->pendingParallelDispatches[] = [
                                'region_id'        => $region->id,
                                'initial_state_id' => $regionInitial->id,
                            ];
                        } else {
                            // No entry actions — run inline, record events here
                            $state->setInternalEventBehavior(
                                type: InternalEvent::PARALLEL_REGION_ENTER,
                                placeholder: $region->route,
                            );
                            $state->setInternalEventBehavior(
                                type: InternalEvent::STATE_ENTER,
                                placeholder: $regionInitial->route,
                            );
                            $regionInitial->runEntryActions($state, $eventBehavior);

                            // Handle machine delegation on region initial state
                            if ($regionInitial->hasMachineInvoke()) {
                                $parallelValues = $state->value;
                                $oldRegionState = $regionInitial->id;

                                $this->handleMachineInvoke($state, $regionInitial, $eventBehavior);

                                if ($state->value !== $parallelValues) {
                                    $newRegionState = $state->value[0] ?? $oldRegionState;
                                    $state->setValues(array_map(
                                        fn (string $v): string => $v === $oldRegionState ? $newRegionState : $v,
                                        $parallelValues,
                                    ));
                                }
                            }
                        }
                    }
                }
            }

            return;
        }

        // Sequential mode: run region entry actions inline
        if ($parallelState->stateDefinitions !== null) {
            foreach ($parallelState->stateDefinitions as $region) {
                // Record region entry
                $state->setInternalEventBehavior(
                    type: InternalEvent::PARALLEL_REGION_ENTER,
                    placeholder: $region->route,
                );

                // Find and run entry actions for the initial state of this region
                $regionInitial = $region->findInitialStateDefinition();
                if ($regionInitial !== null) {
                    $state->setInternalEventBehavior(
                        type: InternalEvent::STATE_ENTER,
                        placeholder: $regionInitial->route,
                    );
                    $regionInitial->runEntryActions($state, $eventBehavior);
                    $this->runEntryListeners($state, $eventBehavior);

                    // Handle machine delegation on region initial state.
                    // handleMachineInvoke may trigger @done/@fail which calls
                    // setCurrentStateDefinition, wiping the parallel value array.
                    // Save and restore the parallel values, merging the region's new state.
                    if ($regionInitial->hasMachineInvoke()) {
                        $parallelValues = $state->value;
                        $oldRegionState = $regionInitial->id;

                        $this->handleMachineInvoke($state, $regionInitial, $eventBehavior);

                        if ($state->value !== $parallelValues) {
                            $newRegionState = $state->value[0] ?? $oldRegionState;
                            $state->setValues(array_map(
                                fn (string $v): string => $v === $oldRegionState ? $newRegionState : $v,
                                $parallelValues,
                            ));
                        }
                    }
                }
            }
        }

        // After all regions entered: check if machine delegation completed
        // all regions to final states, and process parallel @done if so.
        if ($this->areAllRegionsFinal($parallelState, $state)) {
            $this->processParallelOnDone($parallelState, $state, $eventBehavior);
        }
    }

    /**
     * Retrieves the scenario state if scenario is enabled and available; otherwise, returns the current state.
     *
     * @param  State  $state  The current state.
     * @param  EventBehavior|array|null  $eventBehavior  The optional event behavior or event data.
     *
     * @return State|null The scenario state if scenario is enabled and found, otherwise returns the current state.
     */
    public function getScenarioStateIfAvailable(State $state, EventBehavior|array|null $eventBehavior = null): ?State
    {
        if ($this->scenariosEnabled === false) {
            return $state;
        }

        if ($eventBehavior !== null) {
            // Initialize the event and validate it
            $eventBehavior = $this->initializeEvent($eventBehavior, $state);
            if ($eventBehavior->getScenario() !== null) {
                $state->context->set('scenarioType', $eventBehavior->getScenario());
            }
        }

        $scenarioStateKey = str_replace($this->id, $this->id.$this->delimiter.$state->context->get('scenarioType'), $state->currentStateDefinition->id);
        if (isset($this->idMap[$scenarioStateKey]) && $state->context->has('scenarioType')) {
            return $state->setCurrentStateDefinition(stateDefinition: $this->idMap[$scenarioStateKey]);
        }

        return $state;
    }

    /**
     * Builds the current state of the state machine.
     *
     * This method creates a new State object, populating it with
     * the active state definition and the current context data.
     * If no current state is provided, the initial state is used.
     *
     * @param  StateDefinition|null  $currentStateDefinition  The current state definition, if any.
     *
     * @return State The constructed State object representing the current state.
     */
    protected function buildCurrentState(
        ContextManager $context,
        ?StateDefinition $currentStateDefinition = null,
        ?EventBehavior $eventBehavior = null,
    ): State {
        return new State(
            context: $context,
            currentStateDefinition: $currentStateDefinition ?? $this->initialStateDefinition,
            currentEventBehavior: $eventBehavior,
        );
    }

    /**
     * Get the current state definition.
     *
     * If a `State` object is passed, return its active state definition.
     * Otherwise, lookup the state in the `MachineDefinition` states array.
     * If the state is not found, return the initial state.
     *
     * @param  string|State|null  $state  The state to retrieve the definition for.
     *
     * @return mixed The state definition.
     */
    protected function getCurrentStateDefinition(string|State|null $state): mixed
    {
        return $state instanceof State
            ? $state->currentStateDefinition
            : $this->stateDefinitions[$state] ?? $this->initialStateDefinition;
    }

    /**
     * Initializes the context for the state machine.
     *
     * This method checks if the context is defined in the machine's
     * configuration and creates a new `ContextManager` instance
     * accordingly. It supports context defined as an array or a class
     * name.
     *
     * @return ContextManager The initialized context manager
     */
    public function initializeContextFromState(?State $state = null): ContextManager
    {
        // If a state is provided, use it's context
        if (!is_null($state)) {
            return $state->context;
        }

        // If a context class is provided, use it to create the context
        if (!empty($this->behavior['context'])) {
            /** @var ContextManager $contextClass */
            $contextClass = $this->behavior['context'];

            return $contextClass::validateAndCreate($this->config['context'] ?? []);
        }

        // Otherwise, use the context defined in the machine config
        $contextConfig = $this->config['context'] ?? [];

        return ContextManager::validateAndCreate(['data' => $contextConfig]);
    }

    /**
     * Set up the context manager.
     *
     * If a context manager class is specified in the configuration,
     * assign it to the `$behavior['context']` property and clear the `$config['context']` array.
     */
    public function setupContextManager(): void
    {
        if (isset($this->config['context']) && is_subclass_of($this->config['context'], ContextManager::class)) {
            $this->behavior['context'] = $this->config['context'];

            $this->config['context'] = [];
        }
    }

    /**
     * Retrieve an invokable behavior instance or callable.
     *
     * This method checks if the given behavior definition is a valid class and a
     * subclass of InvokableBehavior. If not, it looks up the behavior in the
     * provided behavior type map. If the behavior is still not found, it returns
     * null.
     *
     * @param  string  $behaviorDefinition  The behavior definition to look up.
     * @param  BehaviorType  $behaviorType  The type of the behavior (e.g., guard or action).
     *
     * @return callable|InvokableBehavior|null The invokable behavior instance or callable, or null if not found.
     */
    public function getInvokableBehavior(string $behaviorDefinition, BehaviorType $behaviorType): null|callable|InvokableBehavior
    {
        // If the behavior definition is an InvokableBehavior FQCN, resolve through container.
        if (is_subclass_of($behaviorDefinition, InvokableBehavior::class)) {
            return App::make($behaviorDefinition, ['eventQueue' => $this->eventQueue]);
        }

        // If the guard definition is defined in the machine behavior, retrieve it.
        $invokableBehavior = $this->behavior[$behaviorType->value][$behaviorDefinition] ?? null;

        // If the retrieved behavior is not null and not callable, resolve through container.
        if ($invokableBehavior !== null && !is_callable($invokableBehavior)) {
            return App::make($invokableBehavior, ['eventQueue' => $this->eventQueue]);
        }

        if ($invokableBehavior === null) {
            throw BehaviorNotFoundException::build($behaviorDefinition);
        }

        return $invokableBehavior;
    }

    /**
     * Public proxy for initializing an EventBehavior.
     * Resolves through the machine's event registry for both
     * raw event arrays and EventBehavior instances.
     */
    public function createEventBehavior(EventBehavior|array $event, State $state): EventBehavior
    {
        return $this->initializeEvent($event, $state);
    }

    /**
     * @param  EventBehavior|array  $event  The event to initialize.
     * @param  State  $state  The state in which the event is occurring.
     *
     * @return EventBehavior The initialized EventBehavior instance.
     */
    protected function initializeEvent(
        EventBehavior|array $event,
        State $state
    ): EventBehavior {
        if ($event instanceof EventBehavior) {
            return $this->resolveEventBehaviorThroughRegistry($event, $state);
        }

        if (isset($state->currentStateDefinition->machine->behavior[BehaviorType::Event->value][$event['type']])) {
            /** @var EventBehavior $eventDefinitionClass */
            $eventDefinitionClass = $state
                ->currentStateDefinition
                ->machine
                ->behavior[BehaviorType::Event->value][$event['type']];

            return $eventDefinitionClass::validateAndCreate($event);
        }

        return EventDefinition::from($event);
    }

    /**
     * Resolve an EventBehavior instance through the machine's event registry.
     *
     * When the caller sends an EventBehavior instance, the machine should
     * resolve it through its own registry to ensure its own validation and
     * class are used. This prevents callers from bypassing machine-level
     * event validation by sending a different EventBehavior subclass.
     *
     * @param  EventBehavior  $event  The caller's event instance.
     * @param  State  $state  The current state.
     *
     * @return EventBehavior The resolved event (machine's class or original if not in registry).
     */
    protected function resolveEventBehaviorThroughRegistry(EventBehavior $event, State $state): EventBehavior
    {
        $typeString      = $event->type;
        $registeredClass = $state->currentStateDefinition->machine->behavior[BehaviorType::Event->value][$typeString] ?? null;

        // Not in registry — fall back to caller's instance
        if ($registeredClass === null) {
            return $event;
        }

        // Same class — no re-instantiation needed (optimization)
        if ($event instanceof $registeredClass) {
            return $event;
        }

        // Different class — re-instantiate with machine's registered class, preserving metadata
        return new $registeredClass(
            type: $typeString,
            payload: $event->payload instanceof Optional ? null : $event->payload,
            isTransactional: $event->isTransactional,
            actor: $event->actor($state->context),
            version: $event->version instanceof Optional ? 1 : $event->version,
            source: $event->source,
        );
    }

    /**
     * Retrieves the nearest `StateDefinition` by string.
     *
     * @param  string  $stateDefinitionId  The state string.
     * @param  StateDefinition|null  $source  The source state to resolve relative to.
     *
     * @return StateDefinition|null The nearest StateDefinition or null if it is not found.
     */
    public function getNearestStateDefinitionByString(string $stateDefinitionId, ?StateDefinition $source = null): ?StateDefinition
    {
        if ($stateDefinitionId === '' || $stateDefinitionId === '0') {
            return null;
        }

        // If source is provided, try to resolve relative to it first
        if ($source instanceof StateDefinition) {
            $resolved = $this->resolveStateRelativeToSource($stateDefinitionId, $source);
            if ($resolved instanceof StateDefinition) {
                return $resolved;
            }
        }

        // Fallback to absolute path from machine root
        $absoluteId = $this->id.$this->delimiter.$stateDefinitionId;

        return $this->idMap[$absoluteId] ?? null;
    }

    /**
     * Resolves a state definition ID relative to a source state.
     *
     * Searches up the hierarchy from the source state to find the target.
     * First tries sibling states, then parent's siblings, etc.
     *
     * @param  string  $targetId  The target state ID (can be relative).
     * @param  StateDefinition  $source  The source state to resolve from.
     *
     * @return StateDefinition|null The resolved StateDefinition or null if not found.
     */
    protected function resolveStateRelativeToSource(string $targetId, StateDefinition $source): ?StateDefinition
    {
        // If the target already contains the machine ID, it's absolute
        if (str_starts_with($targetId, $this->id.$this->delimiter)) {
            return $this->idMap[$targetId] ?? null;
        }

        // Start from the source's parent and search up the hierarchy
        $current = $source->parent;

        while ($current instanceof StateDefinition) {
            // Try to find target as a descendant of current state
            $candidateId = $current->id.$this->delimiter.$targetId;
            if (isset($this->idMap[$candidateId])) {
                return $this->idMap[$candidateId];
            }

            // Move up to parent
            $current = $current->parent;
        }

        return null;
    }

    /**
     * Check final states for invalid transition definitions.
     *
     * Iterates through the state definitions in the `idMap` property and checks if any of the final states
     * have transition definitions. If a final state has transition definitions, it throws an `InvalidFinalStateDefinitionException`.
     */
    public function checkFinalStatesForTransitions(): void
    {
        foreach ($this->idMap as $stateDefinition) {
            if (
                $stateDefinition->type === StateDefinitionType::FINAL &&
                $stateDefinition->transitionDefinitions !== null
            ) {
                throw InvalidFinalStateDefinitionException::noTransitions($stateDefinition->id);
            }
        }
    }

    /**
     * Find the transition definition based on the current state definition and event behavior.
     *
     * If the transition definition for the given event type is found in the current state definition,
     * return it. If the current state definition has a parent, recursively search for the transition
     * definition in the parent state definition. If no transition definition is found and the current
     * state definition is not the initial state, throw an exception.
     *
     * @param  StateDefinition  $currentStateDefinition  The current state definition.
     * @param  EventBehavior  $eventBehavior  The event behavior.
     * @param  string|null  $firstStateDefinitionId  The ID of the first state definition encountered during recursion.
     *
     * @return TransitionDefinition|null The found transition definition, or null if none is found.
     *
     * @throws NoTransitionDefinitionFoundException If no transition definition is found for the event type.
     */
    protected function findTransitionDefinition(
        StateDefinition $currentStateDefinition,
        EventBehavior $eventBehavior,
        ?string $firstStateDefinitionId = null,
    ): ?TransitionDefinition {
        $transitionDefinition = $currentStateDefinition->transitionDefinitions[$eventBehavior->type] ?? null;

        // If no transition definition is found, and the current state definition has a parent,
        // recursively search for the transition definition in the parent state definition.
        if (
            $transitionDefinition === null &&
            $currentStateDefinition->order !== 0
        ) {
            return $this->findTransitionDefinition(
                currentStateDefinition: $currentStateDefinition->parent,
                eventBehavior: $eventBehavior,
                firstStateDefinitionId: $currentStateDefinition->id
            );
        }

        // Throw exception if no transition definition is found for the event type
        if ($transitionDefinition === null) {
            throw NoTransitionDefinitionFoundException::build($eventBehavior->type, $firstStateDefinitionId);
        }

        return $transitionDefinition;
    }

    /**
     * Find a transition definition without throwing an exception if not found.
     *
     * Used for parallel states where an event might not be handled by all regions.
     *
     * @param  StateDefinition  $currentStateDefinition  The current state definition.
     * @param  EventBehavior  $eventBehavior  The event behavior.
     *
     * @return TransitionDefinition|null The found transition definition, or null if none is found.
     */
    protected function findTransitionDefinitionOrNull(
        StateDefinition $currentStateDefinition,
        EventBehavior $eventBehavior,
    ): ?TransitionDefinition {
        $transitionDefinition = $currentStateDefinition->transitionDefinitions[$eventBehavior->type] ?? null;

        // If no transition definition is found, and the current state definition has a parent,
        // recursively search for the transition definition in the parent state definition.
        if (
            $transitionDefinition === null &&
            $currentStateDefinition->order !== 0
        ) {
            return $this->findTransitionDefinitionOrNull(
                currentStateDefinition: $currentStateDefinition->parent,
                eventBehavior: $eventBehavior,
            );
        }

        return $transitionDefinition;
    }

    /**
     * Get all active atomic (leaf) states from the current state value.
     *
     * For non-parallel states, returns a single-element array.
     * For parallel states, returns all active leaf states across all regions.
     *
     * @param  State  $state  The current state.
     *
     * @return array<StateDefinition> Array of active atomic state definitions.
     */
    protected function getActiveAtomicStates(State $state): array
    {
        $atomicStates = [];

        foreach ($state->value as $stateId) {
            if (isset($this->idMap[$stateId])) {
                $atomicStates[] = $this->idMap[$stateId];
            }
        }

        return $atomicStates;
    }

    /**
     * Check if a transition source is the parallel state or one of its ancestors.
     *
     * When a transition's source is at or above the parallel state level, the
     * transition "escapes" the parallel state — the entire parallel state must
     * be exited rather than updating individual region values.
     *
     * @param  StateDefinition  $source  The transition source state.
     * @param  StateDefinition  $parallelState  The currently active parallel state.
     *
     * @return bool True if the source escapes the parallel state.
     */
    protected function isParallelEscapeSource(StateDefinition $source, StateDefinition $parallelState): bool
    {
        // Source is the parallel state itself (parallel-level `on` handler)
        if ($source === $parallelState) {
            return true;
        }

        // Source is an ancestor of the parallel state (root-level `on` handler)
        $ancestor = $parallelState->parent;
        while ($ancestor instanceof StateDefinition) {
            if ($ancestor === $source) {
                return true;
            }
            $ancestor = $ancestor->parent;
        }

        return false;
    }

    /**
     * Select transitions for all active regions based on the event.
     *
     * For parallel states, an event is broadcast to all active atomic states.
     * Each region independently evaluates guards and selects a transition.
     * Transitions from the same TransitionDefinition (ancestor-level handlers)
     * are deduplicated to prevent duplicate action execution.
     *
     * @param  EventBehavior  $eventBehavior  The event behavior.
     * @param  State  $state  The current state.
     *
     * @return array<TransitionBranch> Array of valid transition branches.
     */
    protected function selectTransitions(EventBehavior $eventBehavior, State $state): array
    {
        $transitions = [];
        $seen        = [];

        foreach ($this->getActiveAtomicStates($state) as $atomicState) {
            $transitionDef = $this->findTransitionDefinitionOrNull($atomicState, $eventBehavior);

            if ($transitionDef instanceof TransitionDefinition) {
                // Deduplicate: when multiple atomic states resolve to the same
                // TransitionDefinition (ancestor-level handler), keep only one branch.
                $defId = spl_object_id($transitionDef);
                if (isset($seen[$defId])) {
                    continue;
                }
                $seen[$defId] = true;

                $branch = $transitionDef->getFirstValidTransitionBranch($eventBehavior, $state);

                if ($branch instanceof TransitionBranch) {
                    $transitions[] = $branch;
                }
            }
        }

        return $transitions;
    }

    /**
     * Resolve a @done or @fail TransitionDefinition to its winning TransitionBranch.
     *
     * Evaluates guards on the TransitionDefinition branches and returns the first
     * valid branch. Creates a synthetic EventBehavior when none is available
     * (e.g., in async job contexts like ParallelRegionJob).
     *
     * @param  TransitionDefinition  $transition  The @done or @fail transition definition.
     * @param  State  $state  The current machine state.
     * @param  EventBehavior|null  $eventBehavior  The triggering event (null in async contexts).
     *
     * @return TransitionBranch|null The winning branch, or null if no branch qualifies.
     */
    protected function resolveOnDoneOrFailBranch(
        TransitionDefinition $transition,
        State $state,
        ?EventBehavior $eventBehavior,
    ): ?TransitionBranch {
        if (!$eventBehavior instanceof EventBehavior) {
            $eventBehavior = new EventDefinition(type: $transition->event);
        }

        return $transition->getFirstValidTransitionBranch($eventBehavior, $state);
    }

    /**
     * Handle machine delegation when entering a state with a `machine` key.
     *
     * In sync mode (no queue): creates the child machine with resolved context,
     * injects parent identity, runs the child inline to completion,
     * then routes @done/@fail transitions on the parent.
     *
     * @param  State  $state  The parent's current state.
     * @param  StateDefinition  $stateDefinition  The state being entered (has `machine` key).
     * @param  EventBehavior|null  $eventBehavior  The triggering event.
     */
    protected function handleMachineInvoke(State $state, StateDefinition $stateDefinition, ?EventBehavior $eventBehavior): void
    {
        if (!$stateDefinition->hasMachineInvoke()) {
            return;
        }

        $invokeDefinition = $stateDefinition->getMachineInvokeDefinition();

        // Job actor: dispatch as Laravel job
        if ($invokeDefinition->isJob()) {
            $this->handleJobInvoke($state, $stateDefinition, $invokeDefinition);

            return;
        }

        // Short-circuit if child machine is faked (testing)
        if (Machine::isMachineFaked($invokeDefinition->machineClass)) {
            $this->handleFakedMachineInvoke($state, $stateDefinition, $invokeDefinition);

            return;
        }

        // Async mode: dispatch child machine to queue
        if ($invokeDefinition->async) {
            $this->handleAsyncMachineInvoke($state, $stateDefinition, $invokeDefinition);

            return;
        }

        // Resolve child context from parent via `with` config
        $childContext      = $invokeDefinition->resolveChildContext($state->context);
        $childMachineClass = $invokeDefinition->machineClass;

        // Record child machine start event
        $state->setInternalEventBehavior(
            type: InternalEvent::CHILD_MACHINE_START,
            placeholder: $childMachineClass,
        );

        try {
            // Create child machine without starting, so we can inject context first
            /** @var Machine $childMachine */
            $childMachine                           = $childMachineClass::withDefinition($childMachineClass::definition());
            $childMachine->definition->machineClass = $childMachineClass;

            // Merge resolved `with` context into the child definition's initial context.
            // Must mutate $definition->config['context'] (not root->config) because
            // initializeContextFromState() reads from $this->config['context'].
            if ($childContext !== []) {
                $childMachine->definition->config['context'] = array_merge(
                    $childMachine->definition->config['context'] ?? [],
                    $childContext,
                );
            }

            // Start the child (runs entry actions with merged context)
            $childMachine->start();

            $childState = $childMachine->state;

            // Inject parent identity into child's context
            $parentId = $state->context->machineId();
            $childState->context->setMachineIdentity(
                machineId: $childState->context->machineId(),
                parentRootEventId: $parentId,
                parentMachineClass: $this->machineClass,
            );

            // Track child in parent's active children
            $childRootEventId = $childState->history->first()->root_event_id;
            $state->addActiveChild($childRootEventId);

            // Record child done event
            $state->setInternalEventBehavior(
                type: InternalEvent::CHILD_MACHINE_DONE,
                placeholder: $childMachineClass,
            );

            // Clean up: remove child from active list
            $state->removeActiveChild($childRootEventId);

            // Route @done transition on parent
            $this->routeChildDone($state, $stateDefinition, $childMachine, $childMachineClass);
        } catch (\Throwable $e) {
            // Record child fail event
            $state->setInternalEventBehavior(
                type: InternalEvent::CHILD_MACHINE_FAIL,
                placeholder: $childMachineClass,
            );

            // Route @fail transition on parent
            $this->routeChildFail($state, $stateDefinition, $childMachineClass, $e);
        }
    }

    /**
     * Handle a job actor invocation.
     *
     * Dispatches a ChildJobJob to run the Laravel job.
     * For fire-and-forget (target set, no @done), transitions parent immediately.
     * For managed jobs (@done set), parent stays waiting for completion.
     */
    protected function handleJobInvoke(State $state, StateDefinition $stateDefinition, MachineInvokeDefinition $invokeDefinition): void
    {
        $jobClass        = $invokeDefinition->jobClass;
        $jobData         = $invokeDefinition->resolveChildContext($state->context);
        $isFireAndForget = $invokeDefinition->target !== null;

        // Record job start
        $state->setInternalEventBehavior(
            type: InternalEvent::CHILD_MACHINE_START,
            placeholder: $jobClass,
        );

        // Dispatch the job
        $childJobJob = new ChildJobJob(
            parentRootEventId: $state->history?->first()?->root_event_id ?? '',
            parentMachineClass: $this->machineClass ?? '',
            parentStateId: $stateDefinition->id,
            jobClass: $jobClass,
            jobData: $jobData,
            fireAndForget: $isFireAndForget,
        );

        if ($invokeDefinition->queue !== null) {
            $childJobJob->onQueue($invokeDefinition->queue);
        }

        if ($invokeDefinition->connection !== null) {
            $childJobJob->onConnection($invokeDefinition->connection);
        }

        dispatch($childJobJob);

        // Fire-and-forget: transition to target immediately
        if ($isFireAndForget) {
            $targetState = $this->idMap[$this->id.'.'.$invokeDefinition->target]
                ?? $this->idMap[$invokeDefinition->target]
                ?? null;

            if ($targetState instanceof StateDefinition) {
                $state->setCurrentStateDefinition($targetState);
            }
        }
    }

    /**
     * Handle an asynchronous child machine invocation.
     *
     * Creates a MachineChild tracking record, dispatches ChildMachineJob,
     * and optionally dispatches ChildMachineTimeoutJob with configured delay.
     * Parent stays in the invoking state waiting for completion.
     */
    protected function handleAsyncMachineInvoke(State $state, StateDefinition $stateDefinition, MachineInvokeDefinition $invokeDefinition): void
    {
        $childMachineClass = $invokeDefinition->machineClass;
        $childContext      = $invokeDefinition->resolveChildContext($state->context);
        $isFireAndForget   = !$stateDefinition->onDoneTransition instanceof TransitionDefinition
            && $stateDefinition->onDoneStateTransitions === [];

        // Record child machine start event
        $state->setInternalEventBehavior(
            type: InternalEvent::CHILD_MACHINE_START,
            placeholder: $childMachineClass,
        );

        // Create tracking record (managed only — fire-and-forget skips this)
        $machineChildId = '';
        if (!$isFireAndForget) {
            $childRecord = MachineChild::create([
                'parent_root_event_id' => $state->history->first()->root_event_id,
                'parent_state_id'      => $stateDefinition->id,
                'parent_machine_class' => $this->machineClass,
                'child_machine_class'  => $childMachineClass,
                'status'               => MachineChild::STATUS_PENDING,
                'created_at'           => now(),
            ]);

            $state->addActiveChild($childRecord->id);
            $machineChildId = $childRecord->id;
        }

        // Dispatch child machine job
        $job = new ChildMachineJob(
            parentRootEventId: $state->history->first()->root_event_id,
            parentMachineClass: $this->machineClass,
            parentStateId: $stateDefinition->id,
            childMachineClass: $childMachineClass,
            machineChildId: $machineChildId,
            childContext: $childContext,
            retry: $invokeDefinition->retry ?? 1,
            fireAndForget: $isFireAndForget,
        );

        if ($invokeDefinition->queue !== null) {
            $job->onQueue($invokeDefinition->queue);
        }

        if ($invokeDefinition->connection !== null) {
            $job->onConnection($invokeDefinition->connection);
        }

        dispatch($job)->afterCommit();

        // Fire-and-forget: optionally transition to target, then return
        if ($isFireAndForget) {
            if ($invokeDefinition->target !== null) {
                $targetState = $this->idMap[$this->id.'.'.$invokeDefinition->target]
                    ?? $this->idMap[$invokeDefinition->target]
                    ?? null;

                if ($targetState instanceof StateDefinition) {
                    $state->setCurrentStateDefinition($targetState);
                }
            }

            // If no target: method returns, then @always transitions are checked
            // by the caller (transition method) after handleMachineInvoke completes.
            return;
        }

        // Managed: dispatch timeout job if @timeout is configured
        if ($invokeDefinition->timeout !== null && $stateDefinition->onTimeoutTransition instanceof TransitionDefinition) {
            $timeoutJob = new ChildMachineTimeoutJob(
                parentRootEventId: $state->history->first()->root_event_id,
                parentMachineClass: $this->machineClass,
                parentStateId: $stateDefinition->id,
                machineChildId: $childRecord->id,
                childMachineClass: $childMachineClass,
                timeoutSeconds: $invokeDefinition->timeout,
            );

            if ($invokeDefinition->queue !== null) {
                $timeoutJob->onQueue($invokeDefinition->queue);
            }

            dispatch($timeoutJob)->afterCommit()->delay($invokeDefinition->timeout);
        }
    }

    /**
     * Handle a faked machine invocation (testing short-circuit).
     *
     * Instead of creating a real child machine, immediately routes @done or @fail
     * based on the fake configuration. Works for both sync and async delegation.
     * For fire-and-forget (no @done), records invocation and optionally transitions to target.
     */
    protected function handleFakedMachineInvoke(State $state, StateDefinition $stateDefinition, MachineInvokeDefinition $invokeDefinition): void
    {
        $childMachineClass = $invokeDefinition->machineClass;
        $childContext      = $invokeDefinition->resolveChildContext($state->context);

        // Record the invocation for assertion tracking
        Machine::recordMachineInvocation($childMachineClass, $childContext);

        // Record child machine start event
        $state->setInternalEventBehavior(
            type: InternalEvent::CHILD_MACHINE_START,
            placeholder: $childMachineClass,
        );

        // Fire-and-forget faked: optionally transition to target, no @done/@fail routing
        if (!$stateDefinition->onDoneTransition instanceof TransitionDefinition
            && $stateDefinition->onDoneStateTransitions === []) {
            if ($invokeDefinition->target !== null) {
                $targetState = $this->idMap[$this->id.'.'.$invokeDefinition->target]
                    ?? $this->idMap[$invokeDefinition->target]
                    ?? null;

                if ($targetState instanceof StateDefinition) {
                    $state->setCurrentStateDefinition($targetState);
                }
            }

            return;
        }

        $fake = Machine::getMachineFake($childMachineClass);

        if ($fake['fail']) {
            // Record child fail event
            $state->setInternalEventBehavior(
                type: InternalEvent::CHILD_MACHINE_FAIL,
                placeholder: $childMachineClass,
            );

            $failEvent = ChildMachineFailEvent::forChild([
                'error_message' => $fake['error'] ?? 'Faked failure',
                'machine_id'    => '',
                'machine_class' => $childMachineClass,
                'output'        => [],
            ]);

            $this->routeChildFailEvent($state, $stateDefinition, $failEvent);
        } else {
            // Record child done event
            $state->setInternalEventBehavior(
                type: InternalEvent::CHILD_MACHINE_DONE,
                placeholder: $childMachineClass,
            );

            $doneEvent = ChildMachineDoneEvent::forChild([
                'result'        => $fake['result'],
                'output'        => $fake['result'] ?? [],
                'machine_id'    => '',
                'machine_class' => $childMachineClass,
                'final_state'   => $fake['finalState'],
            ]);

            $this->routeChildDoneEvent($state, $stateDefinition, $doneEvent);
        }
    }

    /**
     * Try to forward an unhandled event to a running async child machine.
     *
     * Checks if the current state has forward configuration and if the event type
     * matches. If so, restores the child from DB, sends the forwarded event,
     * and dispatches completion if the child reaches a final state.
     *
     * @return bool True if the event was forwarded, false otherwise.
     */
    protected function tryForwardEventToChild(State $state, StateDefinition $stateDefinition, EventBehavior $eventBehavior): ?State
    {
        if (!$stateDefinition->hasMachineInvoke()) {
            return null;
        }

        $invokeDefinition = $stateDefinition->getMachineInvokeDefinition();

        if (!$invokeDefinition->hasForward()) {
            return null;
        }

        $childEventType = $invokeDefinition->resolveForwardEvent($eventBehavior->type);

        if ($childEventType === null) {
            return null;
        }

        // Find the running child machine from active children
        $childRecord = MachineChild::forParent($state->history->first()->root_event_id)
            ->withStatus(MachineChild::STATUS_RUNNING)
            ->first();

        if ($childRecord === null || $childRecord->child_root_event_id === null) {
            return null;
        }

        // Restore and send the forwarded event to the child
        $childMachineClass = $invokeDefinition->machineClass;
        /** @var Machine $childMachine */
        $childMachine = $childMachineClass::create(state: $childRecord->child_root_event_id);

        // Send the event (possibly renamed) to the child
        $childMachine->send(['type' => $childEventType, 'payload' => $eventBehavior->payload]);

        // If child reached a final state, dispatch completion
        if ($childMachine->state->currentStateDefinition->type === StateDefinitionType::FINAL) {
            $childRecord->markCompleted();

            dispatch(new ChildMachineCompletionJob(
                parentRootEventId: $state->history->first()->root_event_id,
                parentMachineClass: $this->machineClass,
                parentStateId: $stateDefinition->id,
                childMachineClass: $childMachineClass,
                childRootEventId: $childRecord->child_root_event_id,
                success: true,
                result: $childMachine->result(),
                childContextData: $childMachine->state->context->data,
                outputData: self::resolveChildOutput(
                    $childMachine->state->currentStateDefinition,
                    $childMachine->state->context,
                ),
                childFinalState: $childMachine->state->currentStateDefinition->key,
            ));
        }

        return $childMachine->state;
    }

    /**
     * Route a @done transition on the parent after child machine completion (sync mode).
     *
     * Builds a ChildMachineDoneEvent from the child machine and delegates
     * to routeChildDoneEvent().
     */
    protected function routeChildDone(State $state, StateDefinition $stateDefinition, Machine $childMachine, string $childMachineClass): void
    {
        if (!$stateDefinition->onDoneTransition instanceof TransitionDefinition
            && $stateDefinition->onDoneStateTransitions === []) {
            return;
        }

        $childRootEventId = $childMachine->state->history->first()->root_event_id;
        $childContext     = $childMachine->state->context->data;

        $doneEvent = ChildMachineDoneEvent::forChild([
            'result'        => $childMachine->result(),
            'output'        => self::resolveChildOutput($childMachine->state->currentStateDefinition, $childMachine->state->context) ?? $childContext,
            'machine_id'    => $childRootEventId,
            'machine_class' => $childMachineClass,
            'final_state'   => $childMachine->state->currentStateDefinition->key,
        ]);

        $this->routeChildDoneEvent($state, $stateDefinition, $doneEvent);
    }

    /**
     * Resolve the output from a child machine's final state definition.
     *
     * If `output` is an array of key names, filters the context to those keys.
     * If `output` is a Closure, calls it with the context manager.
     * If `output` is null, returns null (caller falls back to full context).
     */
    public static function resolveChildOutput(StateDefinition $finalState, ContextManager $context): ?array
    {
        if ($finalState->output === null) {
            return null;
        }

        if ($finalState->output instanceof \Closure) {
            return ($finalState->output)($context);
        }

        // Array of key names — filter context to those keys
        $output = [];
        foreach ($finalState->output as $key) {
            if ($context->has($key)) {
                $output[$key] = $context->get($key);
            }
        }

        return $output;
    }

    /**
     * Route a @done transition using a pre-built ChildMachineDoneEvent.
     *
     * Resolution order:
     * 1. Try specific @done.{finalState} if the event carries a final state key
     * 2. Fall back to @done catch-all
     * 3. No match → no transition
     *
     * Used by both sync (handleMachineInvoke) and async (ChildMachineCompletionJob).
     */
    public function routeChildDoneEvent(State $state, StateDefinition $stateDefinition, ChildMachineDoneEvent $doneEvent): void
    {
        $finalState = $doneEvent->finalState();

        // 1. Try specific @done.{finalState} first
        if ($finalState !== null && isset($stateDefinition->onDoneStateTransitions[$finalState])) {
            $branch = $this->resolveOnDoneOrFailBranch(
                $stateDefinition->onDoneStateTransitions[$finalState],
                $state,
                $doneEvent,
            );

            if ($branch instanceof TransitionBranch) {
                $state->lastChildDoneRoute = $finalState;
                $this->executeChildTransitionBranch($state, $stateDefinition, $branch, $doneEvent);

                return;
            }
            // Guard failed on specific route → fall through to catch-all
        }

        // 2. Fall back to @done catch-all
        if (!$stateDefinition->onDoneTransition instanceof TransitionDefinition) {
            return;
        }

        $branch = $this->resolveOnDoneOrFailBranch($stateDefinition->onDoneTransition, $state, $doneEvent);

        if (!$branch instanceof TransitionBranch) {
            return;
        }

        $state->lastChildDoneRoute = null;
        $this->executeChildTransitionBranch($state, $stateDefinition, $branch, $doneEvent);
    }

    /**
     * Route a @fail transition on the parent after child machine failure (sync mode).
     *
     * Builds a ChildMachineFailEvent and delegates to routeChildFailEvent().
     * If no @fail is defined, re-throws the exception.
     */
    protected function routeChildFail(State $state, StateDefinition $stateDefinition, string $childMachineClass, \Throwable $exception): void
    {
        if (!$stateDefinition->onFailTransition instanceof TransitionDefinition) {
            throw $exception;
        }

        $failEvent = ChildMachineFailEvent::forChild([
            'error_message' => $exception->getMessage(),
            'machine_id'    => '',
            'machine_class' => $childMachineClass,
            'output'        => [],
        ]);

        $this->routeChildFailEvent($state, $stateDefinition, $failEvent, $exception);
    }

    /**
     * Route a @fail transition using a pre-built ChildMachineFailEvent.
     *
     * Used by both sync (handleMachineInvoke) and async (ChildMachineCompletionJob).
     * If no @fail branch matches and an exception is provided, re-throws it.
     */
    public function routeChildFailEvent(State $state, StateDefinition $stateDefinition, ChildMachineFailEvent $failEvent, ?\Throwable $exception = null): void
    {
        if (!$stateDefinition->onFailTransition instanceof TransitionDefinition) {
            if ($exception instanceof \Throwable) {
                throw $exception;
            }

            return;
        }

        $branch = $this->resolveOnDoneOrFailBranch($stateDefinition->onFailTransition, $state, $failEvent);

        if (!$branch instanceof TransitionBranch) {
            if ($exception instanceof \Throwable) {
                throw $exception;
            }

            return;
        }

        $this->executeChildTransitionBranch($state, $stateDefinition, $branch, $failEvent);
    }

    /**
     * Route a @timeout transition on the parent after child machine timeout.
     *
     * Used by ChildMachineTimeoutJob to fire the @timeout transition branch.
     */
    public function routeChildTimeoutEvent(State $state, StateDefinition $stateDefinition, EventBehavior $timeoutEvent): void
    {
        if (!$stateDefinition->onTimeoutTransition instanceof TransitionDefinition) {
            return;
        }

        $branch = $this->resolveOnDoneOrFailBranch($stateDefinition->onTimeoutTransition, $state, $timeoutEvent);

        if (!$branch instanceof TransitionBranch) {
            return;
        }

        $this->executeChildTransitionBranch($state, $stateDefinition, $branch, $timeoutEvent);
    }

    /**
     * Execute a @done/@fail/@timeout transition branch from child machine routing.
     *
     * Exits the invoking state, runs branch actions, enters the target state.
     */
    protected function executeChildTransitionBranch(
        State $state,
        StateDefinition $sourceState,
        TransitionBranch $branch,
        EventBehavior $eventBehavior,
    ): void {
        if (!$branch->target instanceof StateDefinition) {
            // Targetless: run actions without state change
            $branch->runActions($state, $eventBehavior);

            return;
        }

        $target = $branch->target;

        // Run exit listeners before exit actions
        $this->runExitListeners($state);

        // Exit the invoking state
        $sourceState->runExitActions($state);

        // Run branch actions
        $branch->runActions($state, $eventBehavior);

        // Resolve to initial state if the target is compound
        $initialTarget = $target->findInitialStateDefinition() ?? $target;

        // Update both currentStateDefinition and value array
        $state->setCurrentStateDefinition($initialTarget);

        // Record state enter
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTER,
            placeholder: $initialTarget->route,
        );

        // Run entry actions on target
        $target->runEntryActions($state, $eventBehavior);
        if ($initialTarget !== $target) {
            $initialTarget->runEntryActions($state, $eventBehavior);
        }

        // Run entry and transition listeners
        $this->runEntryListeners($state, $eventBehavior);
        $this->runTransitionListeners($state, $eventBehavior);

        // Handle machine invoke on the new target (nested delegation)
        if ($initialTarget->hasMachineInvoke()) {
            $this->handleMachineInvoke($state, $initialTarget, $eventBehavior);
        }

        // Recursively check if target is final within compound parent
        if ($initialTarget->type === StateDefinitionType::FINAL) {
            $this->processCompoundOnDone($state, $initialTarget, $eventBehavior);
        }
    }

    /**
     * Run root-level lifecycle actions with dedicated internal events.
     *
     * Unlike state-level runEntryActions/runExitActions which record
     * STATE_ENTRY_START/STATE_EXIT_START, this records MACHINE_ENTRY_START
     * or MACHINE_EXIT_START — clearly distinguishing machine lifecycle
     * from state lifecycle in the event history.
     */
    protected function runRootLifecycleActions(
        array $actions,
        State $state,
        ?EventBehavior $eventBehavior = null,
        InternalEvent $startEvent = InternalEvent::MACHINE_ENTRY_START,
        InternalEvent $finishEvent = InternalEvent::MACHINE_ENTRY_FINISH,
    ): void {
        $state->setInternalEventBehavior(type: $startEvent);

        foreach ($actions as $action) {
            $this->runAction(
                actionDefinition: $action,
                state: $state,
                eventBehavior: $eventBehavior,
            );
        }

        $state->setInternalEventBehavior(type: $finishEvent);
    }

    /**
     * Run entry listeners for the current state.
     *
     * Fires after state-level entry actions. Skips transient states.
     * Sync actions run inline, queued actions dispatch ListenerJob.
     */
    public function runEntryListeners(State $state, ?EventBehavior $eventBehavior = null): void
    {
        if ($this->listen['entry'] === []) {
            return;
        }

        if ($this->isTransientState($state->currentStateDefinition)) {
            return;
        }

        $state->setInternalEventBehavior(type: InternalEvent::LISTEN_ENTRY_START);

        foreach ($this->listen['entry'] as $listener) {
            if ($listener['queue']) {
                $this->dispatchListenerJob($listener['action'], $state);
            } else {
                $this->runAction(
                    actionDefinition: $listener['action'],
                    state: $state,
                    eventBehavior: $eventBehavior,
                );
            }
        }

        $state->setInternalEventBehavior(type: InternalEvent::LISTEN_ENTRY_FINISH);
    }

    /**
     * Run exit listeners for the current state.
     *
     * Fires before state-level exit actions. Skips transient states.
     */
    public function runExitListeners(State $state): void
    {
        if ($this->listen['exit'] === []) {
            return;
        }

        if ($this->isTransientState($state->currentStateDefinition)) {
            return;
        }

        $state->setInternalEventBehavior(type: InternalEvent::LISTEN_EXIT_START);

        foreach ($this->listen['exit'] as $listener) {
            if ($listener['queue']) {
                $this->dispatchListenerJob($listener['action'], $state);
            } else {
                $this->runAction(
                    actionDefinition: $listener['action'],
                    state: $state,
                    eventBehavior: $state->currentEventBehavior,
                );
            }
        }

        $state->setInternalEventBehavior(type: InternalEvent::LISTEN_EXIT_FINISH);
    }

    /**
     * Run transition listeners after a transition completes.
     *
     * Fires after entry listeners (or after transition actions for targetless).
     * Skips transient states. This is the most general listener — fires even on targetless transitions.
     */
    public function runTransitionListeners(State $state, ?EventBehavior $eventBehavior = null): void
    {
        if ($this->listen['transition'] === []) {
            return;
        }

        if ($this->isTransientState($state->currentStateDefinition)) {
            return;
        }

        $state->setInternalEventBehavior(type: InternalEvent::LISTEN_TRANSITION_START);

        foreach ($this->listen['transition'] as $listener) {
            if ($listener['queue']) {
                $this->dispatchListenerJob($listener['action'], $state);
            } else {
                $this->runAction(
                    actionDefinition: $listener['action'],
                    state: $state,
                    eventBehavior: $eventBehavior,
                );
            }
        }

        $state->setInternalEventBehavior(type: InternalEvent::LISTEN_TRANSITION_FINISH);
    }

    /**
     * Dispatch a listener action as a queue job.
     *
     * Records LISTEN_QUEUE_DISPATCHED internal event and dispatches ListenerJob.
     * Returns early if no rootEventId (persistence off).
     */
    protected function dispatchListenerJob(string $action, State $state): void
    {
        $rootEventId = $state->history->first()?->root_event_id;

        if ($rootEventId === null) {
            return;
        }

        $state->setInternalEventBehavior(
            type: InternalEvent::LISTEN_QUEUE_DISPATCHED,
            placeholder: $action,
        );

        // Queued listeners require a Machine class to restore state on the worker.
        // TestMachine::define() has no machineClass — skip actual dispatch but keep the internal event.
        if ($this->machineClass === null) {
            return;
        }

        dispatch(new ListenerJob(
            machineClass: $this->machineClass,
            rootEventId: $rootEventId,
            actionClass: $action,
        ));
    }

    /**
     * Parse the 'listen' config key into normalized listener definitions.
     *
     * Each listener entry is normalized to {action: string, queue: bool}.
     * Supports: string, FQCN, array of strings, and ClassName => ['queue' => true] modifier.
     */
    protected function parseListenConfig(?array $config): void
    {
        if (!isset($config['listen'])) {
            return;
        }

        foreach (['entry', 'exit', 'transition'] as $key) {
            if (!isset($config['listen'][$key])) {
                continue;
            }

            $raw = is_array($config['listen'][$key])
                ? $config['listen'][$key]
                : [$config['listen'][$key]];

            foreach ($raw as $k => $v) {
                if (is_int($k)) {
                    $this->listen[$key][] = ['action' => $v, 'queue' => false];
                } else {
                    $this->listen[$key][] = ['action' => $k, 'queue' => $v['queue'] ?? false];
                }
            }
        }
    }

    /**
     * Check if a state is transient (has @always transitions).
     *
     * Transient states are immediately left via @always — listeners skip them
     * to avoid firing on intermediate states the machine passes through instantly.
     */
    protected function isTransientState(StateDefinition $state): bool
    {
        if ($state->transitionDefinitions === null) {
            return false;
        }

        return isset($state->transitionDefinitions[TransitionProperty::Always->value]);
    }

    /**
     * Cancel and clean up active children when exiting a state with machine delegation.
     *
     * Records CHILD_MACHINE_CANCELLED for each active child and clears the list.
     * In sync mode this is a no-op (children complete inline), but provides
     * the infrastructure for async mode exit cleanup.
     */
    protected function cleanupActiveChildren(State $state, StateDefinition $stateDefinition): void
    {
        if (!$stateDefinition->hasMachineInvoke() || !$state->hasActiveChildren()) {
            return;
        }

        foreach ($state->activeChildren as $childRootEventId) {
            $state->setInternalEventBehavior(
                type: InternalEvent::CHILD_MACHINE_CANCELLED,
                placeholder: $stateDefinition->getMachineInvokeDefinition()->machineClass,
            );
        }

        // Mark MachineChild DB records as cancelled (async mode)
        $parentRootEventId = $state->history->first()?->root_event_id;
        if ($parentRootEventId !== null) {
            MachineChild::forParent($parentRootEventId)
                ->active()
                ->each(fn (MachineChild $child) => $child->markCancelled());
        }

        // Clear all active children
        $state->activeChildren = [];
    }

    /**
     * Process compound state onDone transitions.
     *
     * When a transition lands on a final state within a compound sub-state,
     * the compound state's onDone transition should fire. This walks up the
     * parent chain from the final state, stopping at parallel state boundaries.
     *
     * @param  State  $state  The current state.
     * @param  StateDefinition  $finalState  The final state that was just entered.
     * @param  EventBehavior  $eventBehavior  The triggering event.
     */
    protected function processCompoundOnDone(State $state, StateDefinition $finalState, EventBehavior $eventBehavior): void
    {
        $compoundParent = $finalState->parent;

        // Only check the immediate compound parent — onDone does not propagate
        // to grandparent compounds. In XState, when a child reaches final, only
        // its direct parent's onDone handler fires. If the parent has no onDone,
        // the parent stays "internally done" at the final child state.
        if (!$compoundParent instanceof StateDefinition || $compoundParent->type === StateDefinitionType::PARALLEL) {
            return;
        }

        if (!$compoundParent->onDoneTransition instanceof TransitionDefinition) {
            return;
        }

        $branch = $this->resolveOnDoneOrFailBranch($compoundParent->onDoneTransition, $state, $eventBehavior);

        if (!$branch instanceof TransitionBranch) {
            return;
        }

        // Targetless @done: run actions without changing state (XState semantic)
        if (!$branch->target instanceof StateDefinition) {
            $branch->runActions($state, $eventBehavior);

            return;
        }

        $target = $branch->target;

        // Run exit listeners before exit actions
        $this->runExitListeners($state);

        // Exit the final state and the compound parent
        $finalState->runExitActions($state);
        $compoundParent->runExitActions($state);

        // Run branch actions (guards, calculators already evaluated by resolveOnDoneOrFailBranch)
        $branch->runActions($state, $eventBehavior);

        // Resolve to initial state if the target is a compound state
        $initialTarget = $target->findInitialStateDefinition() ?? $target;

        // Update state value: replace the final state's id with the onDone target
        $values = $state->value;
        $idx    = array_search($finalState->id, $values, true);
        if ($idx !== false) {
            $values[$idx] = $initialTarget->id;
            $state->setValues($values);
        }

        // Run entry actions on the target state
        $target->runEntryActions($state, $eventBehavior);
        if ($initialTarget !== $target) {
            $initialTarget->runEntryActions($state, $eventBehavior);
        }

        // Run entry and transition listeners
        $this->runEntryListeners($state, $eventBehavior);
        $this->runTransitionListeners($state, $eventBehavior);

        // Handle machine delegation on the onDone target
        $this->handleMachineInvoke($state, $initialTarget, $eventBehavior);

        // Recursively check if the new state is also final within a compound parent
        if ($initialTarget->type === StateDefinitionType::FINAL) {
            $this->processCompoundOnDone($state, $initialTarget, $eventBehavior);
        }
    }

    /**
     * When all regions are final, the parallel state itself is considered complete
     * and can transition via its onDone handler.
     *
     * @param  StateDefinition  $parallelState  The parallel state definition.
     * @param  State  $state  The current state.
     *
     * @return bool True if all regions are in final states.
     */
    public function areAllRegionsFinal(StateDefinition $parallelState, State $state): bool
    {
        if ($parallelState->type !== StateDefinitionType::PARALLEL || $parallelState->stateDefinitions === null) {
            return false;
        }

        foreach ($parallelState->stateDefinitions as $region) {
            $regionIsFinal = false;

            // Check if any of the active states belong to this region and are final.
            // The final state must be a DIRECT child of the region, not a deeply
            // nested final state within a compound sub-state. For example, if a
            // region has: consent → verification(checking → report_saved[final]) → completed[final]
            // only reaching "completed" should count as the region being final,
            // not "report_saved" which is final only within the verification sub-state.
            foreach ($state->value as $activeStateId) {
                if (str_starts_with($activeStateId, $region->id.$this->delimiter)) {
                    $activeState = $this->idMap[$activeStateId] ?? null;

                    if ($activeState !== null && $activeState->type === StateDefinitionType::FINAL && $activeState->parent === $region) {
                        $regionIsFinal = true;

                        break;
                    }
                }
            }

            if (!$regionIsFinal) {
                return false;
            }
        }

        return true;
    }

    /**
     * Process the onDone transition for a parallel state when all regions are final.
     *
     * Records the PARALLEL_DONE internal event, exits all active child states and the
     * parallel state itself, then transitions to the onDone target.
     *
     * @param  StateDefinition  $parallelState  The parallel state whose regions are all final.
     * @param  State  $state  The current state.
     * @param  EventBehavior|null  $eventBehavior  The triggering event (null when called from ParallelRegionJob).
     */
    public function processParallelOnDone(
        StateDefinition $parallelState,
        State $state,
        ?EventBehavior $eventBehavior = null,
    ): State {
        $state->setInternalEventBehavior(
            type: InternalEvent::PARALLEL_DONE,
            placeholder: $parallelState->route,
        );

        if (!$parallelState->onDoneTransition instanceof TransitionDefinition) {
            return $state;
        }

        $branch = $this->resolveOnDoneOrFailBranch($parallelState->onDoneTransition, $state, $eventBehavior);

        if (!$branch instanceof TransitionBranch) {
            return $state;
        }

        // Targetless @done: run actions without exiting (XState semantic)
        if (!$branch->target instanceof StateDefinition) {
            $branch->runActions($state, $eventBehavior);

            return $state;
        }

        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_START,
            placeholder: "{$parallelState->route}.@done",
        );

        $state = $this->exitParallelStateAndTransitionToTarget($parallelState, $state, $branch, $eventBehavior);

        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_FINISH,
            placeholder: "{$parallelState->route}.@done",
        );

        return $state;
    }

    /**
     * Process the onFail transition for a parallel state when a region fails.
     *
     * Records the PARALLEL_FAIL internal event. If onFail is configured, runs
     * onFail actions (before exit — can inspect parallel state for error context),
     * exits all active child states and the parallel state, then transitions to target.
     *
     * @param  StateDefinition  $parallelState  The parallel state where a failure occurred.
     * @param  State  $state  The current state.
     * @param  EventBehavior|null  $eventBehavior  The triggering event (null when called from ParallelRegionJob).
     */
    public function processParallelOnFail(
        StateDefinition $parallelState,
        State $state,
        ?EventBehavior $eventBehavior = null,
    ): State {
        $state->setInternalEventBehavior(
            type: InternalEvent::PARALLEL_FAIL,
            placeholder: $parallelState->route,
        );

        if (!$parallelState->onFailTransition instanceof TransitionDefinition) {
            return $state;
        }

        $branch = $this->resolveOnDoneOrFailBranch($parallelState->onFailTransition, $state, $eventBehavior);

        if (!$branch instanceof TransitionBranch) {
            return $state;
        }

        // Targetless @fail: run actions without exiting (XState semantic)
        if (!$branch->target instanceof StateDefinition) {
            $branch->runActions($state, $eventBehavior);

            return $state;
        }

        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_START,
            placeholder: "{$parallelState->route}.@fail",
        );

        // Run onFail actions BEFORE exit (can inspect parallel state for error context)
        $state = $this->exitParallelStateAndTransitionToTarget(
            parallelState: $parallelState,
            state: $state,
            branch: $branch,
            eventBehavior: $eventBehavior,
            runActionsBeforeExit: true,
        );

        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_FINISH,
            placeholder: "{$parallelState->route}.@fail",
        );

        return $state;
    }

    /**
     * Check if a final state completes a nested parallel state (parallel within a parallel region).
     *
     * When a transition lands on a final state inside a nested parallel's sub-region,
     * check if all sub-regions of that nested parallel are now final. If so, fire
     * the nested parallel's onDone to transition to its target state.
     */
    protected function processNestedParallelCompletion(
        State $state,
        StateDefinition $finalState,
        EventBehavior $eventBehavior,
    ): void {
        // Walk up: finalState → region → possible nested parallel
        $region = $finalState->parent;
        if (!$region instanceof StateDefinition) {
            return;
        }

        $parallelParent = $region->parent;
        if (!$parallelParent instanceof StateDefinition || $parallelParent->type !== StateDefinitionType::PARALLEL) {
            return;
        }

        // Skip if this IS the outermost parallel (handled by the main check at end of transitionParallelState)
        if ($parallelParent === $state->currentStateDefinition) {
            return;
        }

        if (!$this->areAllRegionsFinal($parallelParent, $state)) {
            return;
        }

        if (!$parallelParent->onDoneTransition instanceof TransitionDefinition) {
            return;
        }

        $branch = $this->resolveOnDoneOrFailBranch($parallelParent->onDoneTransition, $state, $eventBehavior);

        if (!$branch instanceof TransitionBranch) {
            return;
        }

        $state->setInternalEventBehavior(
            type: InternalEvent::PARALLEL_DONE,
            placeholder: $parallelParent->route,
        );

        // Targetless @done: run actions without changing state (XState semantic)
        if (!$branch->target instanceof StateDefinition) {
            $branch->runActions($state, $eventBehavior);

            return;
        }

        $target = $branch->target;

        // Run branch actions
        $branch->runActions($state, $eventBehavior);

        // Run exit actions on all active nested parallel leaf states and collect non-nested values
        $values    = $state->value;
        $newValues = [];
        foreach ($values as $v) {
            $isNested = false;
            foreach ($parallelParent->stateDefinitions as $r) {
                if (str_starts_with($v, $r->id.$this->delimiter)) {
                    $isNested = true;

                    break;
                }
            }
            if ($isNested) {
                // Run exit listeners then exit actions on the leaf state being removed
                $leafState = $this->idMap[$v] ?? null;
                if ($leafState !== null) {
                    $this->runExitListeners($state);
                }
                $leafState?->runExitActions($state);
            } else {
                $newValues[] = $v;
            }
        }

        // Run exit action on the nested parallel state itself
        $parallelParent->runExitActions($state);

        $resolvedTarget = $target->findInitialStateDefinition() ?? $target;
        $newValues[]    = $resolvedTarget->id;
        $state->setValues($newValues);

        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTER,
            placeholder: $resolvedTarget->route,
        );

        $target->runEntryActions($state, $eventBehavior);
        if ($resolvedTarget !== $target) {
            $resolvedTarget->runEntryActions($state, $eventBehavior);
        }

        // Run entry and transition listeners
        $this->runEntryListeners($state, $eventBehavior);
        $this->runTransitionListeners($state, $eventBehavior);

        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTRY_FINISH,
            placeholder: $resolvedTarget->route,
        );

        // Handle machine delegation on the onDone target
        $this->handleMachineInvoke($state, $resolvedTarget, $eventBehavior);

        // Recurse: the onDone target might itself be final
        if ($resolvedTarget->type === StateDefinitionType::FINAL) {
            $this->processCompoundOnDone($state, $resolvedTarget, $eventBehavior);
            $this->processNestedParallelCompletion($state, $resolvedTarget, $eventBehavior);
        }
    }

    /**
     * Exit a parallel state and transition to a target state.
     *
     * Shared logic between processParallelOnDone and processParallelOnFail:
     * runs branch actions, exit actions on all active child states, records region exits,
     * exits the parallel state itself, then transitions to the target.
     *
     * @param  StateDefinition  $parallelState  The parallel state being exited.
     * @param  State  $state  The current state.
     * @param  TransitionBranch  $branch  The resolved transition branch with target and actions.
     * @param  EventBehavior|null  $eventBehavior  The triggering event.
     * @param  bool  $runActionsBeforeExit  If true, run branch actions before exit (for @fail error inspection).
     */
    protected function exitParallelStateAndTransitionToTarget(
        StateDefinition $parallelState,
        State $state,
        TransitionBranch $branch,
        ?EventBehavior $eventBehavior,
        bool $runActionsBeforeExit = false,
    ): State {
        $targetState = $branch->target;

        if (!$targetState instanceof StateDefinition) {
            return $state;
        }

        // Run branch actions BEFORE exit if requested (for @fail — can inspect parallel state for error context)
        if ($runActionsBeforeExit) {
            $branch->runActions($state, $eventBehavior);
        }

        // Run exit listeners then exit actions on all active states and record region exits
        foreach ($state->value as $activeStateId) {
            $activeState = $this->idMap[$activeStateId] ?? null;
            if ($activeState !== null) {
                $this->runExitListeners($state);
            }
            $activeState?->runExitActions($state);

            // Find and record region exit
            $regionParent = $activeState?->parent;
            while ($regionParent !== null && $regionParent->parent !== $parallelState) {
                $regionParent = $regionParent->parent;
            }
            if ($regionParent !== null) {
                $state->setInternalEventBehavior(
                    type: InternalEvent::PARALLEL_REGION_EXIT,
                    placeholder: $regionParent->route,
                );
            }
        }

        // Run exit action on parallel state itself
        $parallelState->runExitActions($state);

        // Run branch actions AFTER exit if not already run before exit
        if (!$runActionsBeforeExit) {
            $branch->runActions($state, $eventBehavior);
        }

        // Transition to the target state (use target itself if atomic/final)
        $initialState                  = $targetState->findInitialStateDefinition() ?? $targetState;
        $state->currentStateDefinition = $initialState;
        $state->value                  = [$state->currentStateDefinition->id];

        // Run entry actions on target state (and initial if different)
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTER,
            placeholder: $initialState->route,
        );

        $targetState->runEntryActions($state, $eventBehavior);
        if ($initialState !== $targetState) {
            $initialState->runEntryActions($state, $eventBehavior);
        }

        // Run entry and transition listeners
        $this->runEntryListeners($state, $eventBehavior);
        $this->runTransitionListeners($state, $eventBehavior);

        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTRY_FINISH,
            placeholder: $state->currentStateDefinition->route,
        );

        return $state;
    }

    /**
     * Transition a parallel state by broadcasting the event to all active regions.
     *
     * For parallel states, events are sent to all active atomic states.
     * Each region independently evaluates if it can handle the event.
     *
     * @param  State  $state  The current state with multiple active regions.
     * @param  EventBehavior  $eventBehavior  The event to process.
     *
     * @return State The new state after transitions.
     *
     * @throws NoTransitionDefinitionFoundException If no region can handle the event.
     */
    protected function transitionParallelState(State $state, EventBehavior $eventBehavior, int $recursionDepth = 0): State
    {
        // Find transitions for all active atomic states
        $transitions = $this->selectTransitions($eventBehavior, $state);

        // If no transitions found for a real event, throw exception.
        // For @always transitions, guard failure is expected (e.g., cross-region
        // sync where a region waits for a sibling to reach a certain state).
        // In that case, return the current state without transitioning.
        if ($transitions === []) {
            if ($eventBehavior->type === TransitionProperty::Always->value) {
                return $state;
            }

            throw NoTransitionDefinitionFoundException::build(
                $eventBehavior->type,
                $state->currentStateDefinition->id
            );
        }

        // Check for escape transitions: when the transition source is the parallel
        // state itself or an ancestor (root-level `on`, parallel-level `on`), the
        // entire parallel state must be exited — not individual region values.
        $parallelState = $state->currentStateDefinition;
        foreach ($transitions as $branch) {
            $source = $branch->transitionDefinition->source;
            if ($this->isParallelEscapeSource($source, $parallelState)) {
                $state->setInternalEventBehavior(
                    type: InternalEvent::TRANSITION_START,
                    placeholder: "{$parallelState->route}.{$eventBehavior->type}",
                );

                $state = $this->exitParallelStateAndTransitionToTarget(
                    parallelState: $parallelState,
                    state: $state,
                    branch: $branch,
                    eventBehavior: $eventBehavior,
                );

                $state->setInternalEventBehavior(
                    type: InternalEvent::TRANSITION_FINISH,
                    placeholder: "{$parallelState->route}.{$eventBehavior->type}",
                );

                return $state;
            }
        }

        // Record transition start
        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_START,
            placeholder: "{$state->currentStateDefinition->route}.{$eventBehavior->type}",
        );

        // Process each transition - update state values immediately after each region
        foreach ($transitions as $transitionBranch) {
            /** @var TransitionBranch $transitionBranch */
            $sourceState = $transitionBranch->transitionDefinition->source;

            // Get the target state, resolving to initial state if needed
            $targetState = $transitionBranch->target?->findInitialStateDefinition()
                ?? $transitionBranch->target
                ?? $sourceState;

            // Execute transition actions
            $transitionBranch->runActions($state, $eventBehavior);

            // Run exit listeners then exit actions for the source state
            $this->runExitListeners($state);
            $sourceState->runExitActions($state);

            // Update the state value for this region immediately
            // This ensures entry actions and subsequent events see the correct value
            $currentValues = $state->value;
            $regionIndex   = array_search($sourceState->id, $currentValues, true);
            if ($regionIndex !== false) {
                // If target is a parallel state, expand to all its initial leaf states
                if ($targetState->type === StateDefinitionType::PARALLEL) {
                    $initialStates = $targetState->findAllInitialStateDefinitions();
                    // Remove the source state and insert all initial states at that position
                    array_splice($currentValues, (int) $regionIndex, 1, array_map(
                        fn (StateDefinition $s): string => $s->id,
                        $initialStates
                    ));
                    $state->setValues($currentValues);

                    // Run entry actions for the parallel state itself first
                    $targetState->runEntryActions($state, $eventBehavior);

                    // Check if nested parallel should dispatch
                    if ($this->shouldDispatchParallel($targetState)) {
                        foreach ($targetState->stateDefinitions as $region) {
                            $regionInitial = $region->findInitialStateDefinition();
                            if ($regionInitial !== null) {
                                if ($regionInitial->entry !== null && $regionInitial->entry !== []) {
                                    $this->pendingParallelDispatches[] = [
                                        'region_id'        => $region->id,
                                        'initial_state_id' => $regionInitial->id,
                                    ];
                                } else {
                                    $regionInitial->runEntryActions($state, $eventBehavior);

                                    // Handle machine delegation on region initial state
                                    if ($regionInitial->hasMachineInvoke()) {
                                        $parallelValues = $state->value;
                                        $oldRegionState = $regionInitial->id;

                                        $this->handleMachineInvoke($state, $regionInitial, $eventBehavior);

                                        if ($state->value !== $parallelValues) {
                                            $newRegionState = $state->value[0] ?? $oldRegionState;
                                            $state->setValues(array_map(
                                                fn (string $v): string => $v === $oldRegionState ? $newRegionState : $v,
                                                $parallelValues,
                                            ));
                                        }

                                        $state->currentStateDefinition = $parallelState;
                                    }
                                }
                            }
                        }
                    } else {
                        // Sequential: run entry actions for each initial state
                        foreach ($initialStates as $initialState) {
                            $initialState->runEntryActions($state, $eventBehavior);

                            // Handle machine delegation on region initial state
                            if ($initialState->hasMachineInvoke()) {
                                $parallelValues = $state->value;
                                $oldRegionState = $initialState->id;

                                $this->handleMachineInvoke($state, $initialState, $eventBehavior);

                                if ($state->value !== $parallelValues) {
                                    $newRegionState = $state->value[0] ?? $oldRegionState;
                                    $state->setValues(array_map(
                                        fn (string $v): string => $v === $oldRegionState ? $newRegionState : $v,
                                        $parallelValues,
                                    ));
                                }

                                $state->currentStateDefinition = $parallelState;
                            }
                        }
                    }
                } else {
                    $currentValues[$regionIndex] = $targetState->id;
                    $state->setValues($currentValues);

                    // Execute entry actions for the target state
                    $targetState->runEntryActions($state, $eventBehavior);
                    $this->runEntryListeners($state, $eventBehavior);

                    // Handle machine delegation on the target state
                    if ($targetState->hasMachineInvoke()) {
                        $parallelValues = $state->value;
                        $oldRegionState = $targetState->id;

                        $this->handleMachineInvoke($state, $targetState, $eventBehavior);

                        if ($state->value !== $parallelValues) {
                            $newRegionState = $state->value[0] ?? $oldRegionState;
                            $state->setValues(array_map(
                                fn (string $v): string => $v === $oldRegionState ? $newRegionState : $v,
                                $parallelValues,
                            ));
                        }

                        // Restore currentStateDefinition to the parallel state
                        $state->currentStateDefinition = $parallelState;
                    }
                }
            } else {
                // Execute entry actions for the target state (no state value update needed)
                $targetState->runEntryActions($state, $eventBehavior);
                $this->runEntryListeners($state, $eventBehavior);

                // Handle machine delegation on the target state
                if ($targetState->hasMachineInvoke()) {
                    $parallelValues = $state->value;
                    $oldRegionState = $targetState->id;

                    $this->handleMachineInvoke($state, $targetState, $eventBehavior);

                    if ($state->value !== $parallelValues) {
                        $newRegionState = $state->value[0] ?? $oldRegionState;
                        $state->setValues(array_map(
                            fn (string $v): string => $v === $oldRegionState ? $newRegionState : $v,
                            $parallelValues,
                        ));
                    }

                    // Restore currentStateDefinition to the parallel state
                    $state->currentStateDefinition = $parallelState;
                }
            }

            // Process compound state onDone: when a transition lands on a final state
            // that is a child of a compound sub-state (not a direct child of a parallel
            // region), fire the compound state's onDone transition.
            if ($targetState->type === StateDefinitionType::FINAL) {
                $this->processCompoundOnDone($state, $targetState, $eventBehavior);
                $this->processNestedParallelCompletion($state, $targetState, $eventBehavior);
            }
        }

        // Run transition listeners after all regions processed
        $this->runTransitionListeners($state, $eventBehavior);

        // Record transition finish with updated state values
        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_FINISH,
            placeholder: "{$state->currentStateDefinition->route}.{$eventBehavior->type}",
        );

        // Check for always transitions in new states
        foreach ($state->value as $stateId) {
            $stateDefinition = $this->idMap[$stateId] ?? null;
            if ($stateDefinition?->transitionDefinitions !== null) {
                foreach ($stateDefinition->transitionDefinitions as $transition) {
                    if ($transition->isAlways === true) {
                        return $this->transition(
                            event: [
                                'type'  => TransitionProperty::Always->value,
                                'actor' => $eventBehavior->actor($state->context),
                            ],
                            state: $state,
                            recursionDepth: $recursionDepth + 1,
                        );
                    }
                }
            }
        }

        // Check for parallel completion (all regions in final states)
        if ($this->areAllRegionsFinal($state->currentStateDefinition, $state)) {
            return $this->processParallelOnDone($state->currentStateDefinition, $state, $eventBehavior);
        }

        // Process event queue
        if ($this->eventQueue->isNotEmpty()) {
            $firstEvent    = $this->eventQueue->shift();
            $eventBehavior = $this->initializeEvent($firstEvent, $state);

            return $this->transition($eventBehavior, $state, recursionDepth: $recursionDepth + 1);
        }

        return $state;
    }

    // endregion

    // region Public Methods

    /**
     * Transition the state machine to a new state based on an event.
     *
     * @param  EventBehavior|array  $event  The event that triggers the transition.
     * @param  State|null  $state  The current state or state name, or null to use the initial state.
     * @param  int  $recursionDepth  The current recursive transition depth within this macrostep.
     *
     * @return State The new state after the transition.
     *
     * @throws MaxTransitionDepthExceededException If the recursive transition depth exceeds the configured limit.
     */
    public function transition(
        EventBehavior|array $event,
        ?State $state = null,
        int $recursionDepth = 0,
    ): State {
        $maxDepth = max(1, (int) config('machine.max_transition_depth', self::DEFAULT_MAX_TRANSITION_DEPTH));
        if ($recursionDepth >= $maxDepth) {
            throw MaxTransitionDepthExceededException::exceeded(
                limit: $maxDepth,
                route: $state?->currentStateDefinition->route ?? 'unknown',
            );
        }

        if ($state instanceof State) {
            $state = $this->getScenarioStateIfAvailable(state: $state, eventBehavior: $event);
        } else {
            // Use the initial state if no state is provided
            $state = $this->getInitialState(event: $event);
        }

        $currentStateDefinition = $this->getCurrentStateDefinition($state);

        // Initialize the event and validate it
        $eventBehavior = $this->initializeEvent($event, $state);
        $eventBehavior->selfValidate();

        // Track the triggering event for @always chains.
        // For real events: store as triggeringEvent and set as currentEventBehavior.
        // For @always: preserve triggeringEvent and expose it as currentEventBehavior
        // so both access paths (parameter injection and $state->currentEventBehavior) are consistent.
        if ($eventBehavior->type !== TransitionProperty::Always->value) {
            $state->triggeringEvent = $eventBehavior;
            $state->setCurrentEventBehavior($eventBehavior);
        } elseif ($state->triggeringEvent !== null) {
            $state->setCurrentEventBehavior($state->triggeringEvent);
        } else {
            $state->setCurrentEventBehavior($eventBehavior);
        }

        // For parallel states, find transitions across all active atomic states
        if ($state->isInParallelState()) {
            return $this->transitionParallelState($state, $eventBehavior, $recursionDepth);
        }

        /*
         * Get the transition definition for the current event type.
         *
         * @var null|array|TransitionDefinition $transitionDefinition
         */
        try {
            $transitionDefinition = $this->findTransitionDefinition($currentStateDefinition, $eventBehavior);
        } catch (NoTransitionDefinitionFoundException $e) {
            // Check if event should be forwarded to a running child machine
            $childState = $this->tryForwardEventToChild($state, $currentStateDefinition, $eventBehavior);

            if ($childState instanceof State) {
                $state->setForwardedChildState($childState);

                return $state;
            }

            throw $e;
        }

        // Record transition start event
        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_START,
            placeholder: "{$state->currentStateDefinition->route}.{$eventBehavior->type}",
        );

        $transitionBranch = $transitionDefinition->getFirstValidTransitionBranch(
            eventBehavior: $eventBehavior,
            state: $state
        );

        // If no valid transition branch is found, return the current state
        if (!$transitionBranch instanceof TransitionBranch) {
            // Record transition abort event
            $state->setInternalEventBehavior(
                type: InternalEvent::TRANSITION_FAIL,
                placeholder: "{$state->currentStateDefinition->route}.{$eventBehavior->type}",
            );

            return $state->setCurrentStateDefinition($currentStateDefinition);
        }

        // If a target state definition is defined, find its initial state definition
        $targetStateDefinition = $transitionBranch->target?->findInitialStateDefinition() ?? $transitionBranch->target;

        // Execute actions associated with the transition
        $transitionBranch->runActions($state, $eventBehavior);

        // Record transition start finish
        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_FINISH,
            placeholder: "{$state->currentStateDefinition->route}.{$eventBehavior->type}",
        );

        // Run exit listeners before state exit actions (only for targeted transitions)
        if ($targetStateDefinition instanceof StateDefinition) {
            $this->runExitListeners($state);
        }

        // Execute exit actions for the current state definition
        $transitionBranch->transitionDefinition->source->runExitActions($state);

        // Cancel active children when leaving a state with machine delegation
        $this->cleanupActiveChildren($state, $transitionBranch->transitionDefinition->source);

        // Record state exit event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_EXIT,
            placeholder: $state->currentStateDefinition->route,
        );

        // Set the new state, or keep the current state if no target state definition is defined
        $newState = $state
            ->setCurrentStateDefinition($targetStateDefinition ?? $currentStateDefinition);

        // Get scenario state if exists
        $newState = $this->getScenarioStateIfAvailable(state: $newState, eventBehavior: $eventBehavior);
        if ($targetStateDefinition instanceof StateDefinition && $targetStateDefinition->id !== $newState->currentStateDefinition->id) {
            $targetStateDefinition = $newState->currentStateDefinition;
        }

        // Handle entering a parallel state from a non-parallel state
        if ($targetStateDefinition?->type === StateDefinitionType::PARALLEL) {
            $this->enterParallelState($newState, $targetStateDefinition, $eventBehavior);

            // Check @always transitions on active region states
            foreach ($newState->value as $stateId) {
                $stateDefinition = $this->idMap[$stateId] ?? null;
                if ($stateDefinition?->transitionDefinitions !== null) {
                    foreach ($stateDefinition->transitionDefinitions as $transition) {
                        if ($transition->isAlways === true) {
                            return $this->transition(
                                event: [
                                    'type'  => TransitionProperty::Always->value,
                                    'actor' => $eventBehavior->actor($newState->context),
                                ],
                                state: $newState,
                                recursionDepth: $recursionDepth + 1,
                            );
                        }
                    }
                }
            }

            // Process event queue
            if ($this->eventQueue->isNotEmpty()) {
                $firstEvent    = $this->eventQueue->shift();
                $eventBehavior = $this->initializeEvent($firstEvent, $newState);

                return $this->transition($eventBehavior, $newState, recursionDepth: $recursionDepth + 1);
            }

            return $newState;
        }

        // Record state enter event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTER,
            placeholder: $state->currentStateDefinition->route,
        );

        // Execute entry actions for the new state definition
        $targetStateDefinition?->runEntryActions($newState, $eventBehavior);

        // Run entry and transition listeners
        if ($targetStateDefinition !== null) {
            $this->runEntryListeners($newState, $eventBehavior);
        }
        $this->runTransitionListeners($newState, $eventBehavior);

        // Handle machine delegation (sync mode): launch child inline after entry actions
        if ($targetStateDefinition !== null) {
            $this->handleMachineInvoke($newState, $targetStateDefinition, $eventBehavior);
        }

        // Process compound state onDone: when a non-parallel transition lands on a final
        // state that is a child of a compound parent, fire the compound state's @done.
        if ($targetStateDefinition?->type === StateDefinitionType::FINAL) {
            $this->processCompoundOnDone($newState, $targetStateDefinition, $eventBehavior);
        }

        // Check if the new state has any transitions that are always taken
        if ($this->idMap[$newState->currentStateDefinition->id]->transitionDefinitions !== null) {
            /** @var TransitionDefinition $transition */
            foreach ($this->idMap[$newState->currentStateDefinition->id]->transitionDefinitions as $transition) {
                if ($transition->isAlways === true) {
                    // If an always-taken transition is found, perform the transition
                    return $this->transition(
                        event: [
                            'type'  => TransitionProperty::Always->value,
                            'actor' => $eventBehavior->actor($newState->context),
                        ],
                        state: $newState,
                        recursionDepth: $recursionDepth + 1,
                    );
                }
            }
        }

        // If there are events in the queue, process the first event
        if ($this->eventQueue->isNotEmpty()) {
            $firstEvent = $this->eventQueue->shift();

            $eventBehavior = $this->initializeEvent($firstEvent, $newState);

            return $this->transition($eventBehavior, $newState, recursionDepth: $recursionDepth + 1);
        }

        // Record the machine finish event if the current state is a final state.
        if ($state->currentStateDefinition->type === StateDefinitionType::FINAL) {
            // Run root-level exit actions (machine lifecycle — runs once on completion)
            if ($this->root->exit !== []) {
                $this->runRootLifecycleActions(
                    actions: $this->root->exit,
                    state: $state,
                    startEvent: InternalEvent::MACHINE_EXIT_START,
                    finishEvent: InternalEvent::MACHINE_EXIT_FINISH,
                );
            }

            $state->setInternalEventBehavior(
                type: InternalEvent::MACHINE_FINISH,
                placeholder: $state->currentStateDefinition->route,
            );
        }

        return $newState;
    }

    /**
     * Executes the action associated with the provided action definition.
     *
     * This method retrieves the appropriate action behavior based on the
     * action definition, and if the action behavior is callable, it
     * executes it using the context and event payload.
     *
     * @param  string  $actionDefinition  The action definition, either a class
     * @param  EventBehavior|null  $eventBehavior  The event (optional).
     *
     * @throws \ReflectionException
     */
    public function runAction(
        string $actionDefinition,
        State $state,
        ?EventBehavior $eventBehavior = null
    ): void {
        [$actionDefinition, $actionArguments] = array_pad(explode(':', $actionDefinition, 2), 2, null);
        $actionArguments                      = $actionArguments === null ? [] : explode(',', $actionArguments);

        // Retrieve the appropriate action behavior based on the action definition.
        $actionBehavior = $this->getInvokableBehavior(
            behaviorDefinition: $actionDefinition,
            behaviorType: BehaviorType::Action
        );

        $shouldLog = $actionBehavior?->shouldLog ?? false;

        // If the action behavior is callable, execute it with the context and event payload.
        if (!is_callable($actionBehavior)) {
            return;
        }

        // Record the internal action init event.
        $state->setInternalEventBehavior(
            type: InternalEvent::ACTION_START,
            placeholder: $actionDefinition,
            shouldLog: $shouldLog,
        );

        if ($actionBehavior instanceof InvokableBehavior && !$actionBehavior instanceof MockInterface) {
            $actionBehavior::validateRequiredContext($state->context);
        }

        // Get the number of events in the queue before the action is executed.
        $numberOfEventsInQueue = $this->eventQueue->count();

        // Inject action behavior parameters
        $actionBehaviorParemeters = InvokableBehavior::injectInvokableBehaviorParameters(
            actionBehavior: $actionBehavior,
            state: $state,
            eventBehavior: $eventBehavior,
            actionArguments: $actionArguments
        );

        // Execute the action behavior
        if (InlineBehaviorFake::intercept($actionDefinition, $actionBehaviorParemeters)) {
            $replacement = InlineBehaviorFake::getReplacement($actionDefinition);
            ($replacement)(...$actionBehaviorParemeters);
        } else {
            ($actionBehavior)(...$actionBehaviorParemeters);
        }

        // Get the number of events in the queue after the action is executed.
        $newNumberOfEventsInQueue = $this->eventQueue->count();

        // If the number of events in the queue has changed, get the new events to create history.
        if ($numberOfEventsInQueue !== $newNumberOfEventsInQueue) {
            // Get new events from the queue
            $newEvents = $this->eventQueue->slice($numberOfEventsInQueue, $newNumberOfEventsInQueue);

            foreach ($newEvents as $newEvent) {
                $state->setInternalEventBehavior(
                    type: InternalEvent::EVENT_RAISED,
                    placeholder: is_array($newEvent) ? $newEvent['type'] : $newEvent->type,
                );
            }
        }

        // Validate the context after the action is executed.
        $state->context->selfValidate();

        // Record the internal action done event.
        $state->setInternalEventBehavior(
            type: InternalEvent::ACTION_FINISH,
            placeholder: $actionDefinition,
            shouldLog: $shouldLog,
        );
    }

    // endregion
}

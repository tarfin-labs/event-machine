<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Routing\EndpointDefinition;
use Tarfinlabs\EventMachine\Support\BehaviorTupleParser;
use Tarfinlabs\EventMachine\Exceptions\InvalidStateConfigException;
use Tarfinlabs\EventMachine\Exceptions\InvalidOutputDefinitionException;
use Tarfinlabs\EventMachine\Exceptions\InvalidParallelStateDefinitionException;

class StateDefinition
{
    // region Public Properties

    /** The root machine definition this state definition belongs to. */
    public MachineDefinition $machine;

    /** The parent state definition. */
    public ?StateDefinition $parent;

    /** The key of the state definition, representing its location in the overall state value. */
    public ?string $key;

    /** The unique id of the state definition. */
    public string $id;

    /**
     * The string path from the root machine definition to this state definition.
     *
     * @var array<string>
     */
    public array $path;

    /** The string route from the root machine definition to this state definition. */
    public string $route;

    /** The description of the state definition. */
    public ?string $description;

    /** The order this state definition appears. */
    public int $order = -1;

    /**
     * The child state definitions of this state definition.
     *
     * @var null|array<StateDefinition>
     */
    public ?array $stateDefinitions = null;

    /** The type of this state definition. */
    public StateDefinitionType $type;

    /**
     * The transition definitions of this state definition.
     *
     * @var null|array<TransitionDefinition>
     */
    public ?array $transitionDefinitions = null;

    /**
     * The events that can be accepted by this state definition.
     *
     * @var null|array<string>
     */
    public ?array $events = null;

    /** The initial state definition for this machine definition. */
    public ?StateDefinition $initialStateDefinition = null;

    /** The transition definition for @done, resolved at init time for guard support. */
    public ?TransitionDefinition $onDoneTransition = null;

    /** The transition definition for @fail, resolved at init time for guard support. */
    public ?TransitionDefinition $onFailTransition = null;

    /** The transition definition for @timeout, resolved at init time. */
    public ?TransitionDefinition $onTimeoutTransition = null;

    /** @var array<string, TransitionDefinition> Per-final-state @done.{state} transitions, keyed by child final state name. */
    public array $onDoneStateTransitions = [];

    /** Machine invoke definition when this state delegates to a child machine. */
    public ?MachineInvokeDefinition $machineInvokeDefinition = null;

    /**
     * The action(s) to be executed upon entering the state definition.
     *
     * @var null|array<string|callable>
     */
    public ?array $entry = [];

    /**
     * The action(s) to be executed upon exiting the state definition.
     *
     * @var null|array<string|callable>
     */
    public ?array $exit = [];

    /**
     * The meta data associated with this state definition,
     * which will be returned in {@see State} instances.
     *
     * @var null|array<mixed>
     */
    public ?array $meta = null;

    /**
     * Output definition for this state. Controls what data is exposed
     * to external consumers (API endpoints, broadcasts, parent machines).
     *
     * @var null|string|array<string>|\Closure
     */
    public null|string|array|\Closure $output = null;

    // endregion

    // region Constructor

    /**
     * Create a new state definition with the given configuration and options.
     *
     * @param  array<string|int, mixed>|null  $config  The raw configuration array used to create the state definition.
     * @param  array<string, mixed>|null  $options  The `options` array for configuring the state definition.
     */
    public function __construct(
        public ?array $config,
        ?array $options = null,
    ) {
        $this->initializeOptions($options);

        $this->path        = $this->buildPath();
        $this->route       = $this->buildRoute();
        $this->id          = $this->buildId();
        $this->description = $this->buildDescription();

        $this->order = count($this->machine->idMap);

        $this->machine->idMap[$this->id] = $this;

        $this->stateDefinitions = $this->createChildStateDefinitions();
        $this->type             = $this->getStateDefinitionType();

        if ($this->type === StateDefinitionType::FINAL) {
            $this->initializeResults();
        }

        $this->initializeOutput();

        $this->events = $this->collectUniqueEvents();

        $this->initialStateDefinition = $this->findInitialStateDefinition();

        $this->initializeEntryActions();
        $this->initializeExitActions();
        $this->initializeMachineInvoke();

        $this->meta = $this->config['meta'] ?? null;
    }

    // endregion

    // region Protected Methods

    /**
     * Initialize the path for this state definition by appending its key to the parent's path.
     *
     * @return array<string> The path for this state definition.
     */
    protected function buildPath(): array
    {
        return $this->parent instanceof StateDefinition
            ? array_merge($this->parent->path, [$this->key])
            : [];
    }

    /**
     * Build the route by concatenating the path elements with the delimiter.
     *
     * @return string The built route as a string.
     */
    protected function buildRoute(): string
    {
        return implode($this->machine->delimiter, $this->path);
    }

    /**
     * Initialize id for this state definition by concatenating
     * the machine id, path, and delimiter.
     *
     * @return string The global id for this state definition.
     */
    protected function buildId(): string
    {
        return $this->config['id'] ?? implode($this->machine->delimiter, array_merge([$this->machine->id], $this->path));
    }

    /**
     * Initialize the description for this state definition.
     */
    protected function buildDescription(): ?string
    {
        return $this->config['description'] ?? null;
    }

    /**
     * Initialize the child state definitions for this state definition by iterating through
     * the 'states' configuration and creating new StateDefinition instances.
     *
     * @return ?array<StateDefinition> An array of child state definitions or null if no child states are defined.
     */
    protected function createChildStateDefinitions(): ?array
    {
        if (!isset($this->config['states']) || !is_array($this->config['states'])) {
            return null;
        }

        $states = [];
        foreach ($this->config['states'] as $stateName => $stateConfig) {
            $states[$stateName] = new StateDefinition(
                config: $stateConfig,
                options: [
                    'parent'  => $this,
                    'machine' => $this->machine,
                    'key'     => $stateName,
                ]
            );
        }

        return $states;
    }

    /**
     * Initialize the options for this state definition.
     */
    /**
     * @param  array<string, mixed>|null  $options
     */
    protected function initializeOptions(?array $options): void
    {
        $this->parent  = $options['parent'] ?? null;
        $this->machine = $options['machine'] ?? null;
        $this->key     = $options['key'] ?? null;
    }

    /**
     * Initialize the output behavior for the current state in the behavior registry.
     */
    protected function initializeResults(): void
    {
        $outputDef = $this->config['output'] ?? null;

        if ($outputDef !== null) {
            $this->machine->behavior[BehaviorType::Output->value][$this->id] = $outputDef;
        }
    }

    /**
     * Initialize the output definition for this state.
     *
     * Output controls what data this state exposes to external consumers
     * (API endpoints, broadcasts, parent machines). Accepts an array of
     * key names, an OutputBehavior class, an inline key, or a Closure.
     *
     * Validation rules:
     * - Transient states (@always) cannot define output (never observed)
     * - Parallel region states cannot define output (only the parallel state itself can)
     */
    protected function initializeOutput(): void
    {
        if (!isset($this->config['output'])) {
            return;
        }

        // Transient states (@always) are never observed — output would never be read
        if ($this->isTransient()) {
            throw InvalidOutputDefinitionException::transientState($this->route);
        }

        // Parallel region states cannot define output — only the parallel state itself can
        if ($this->isInsideParallelRegion()) {
            throw InvalidOutputDefinitionException::parallelRegionState($this->route);
        }

        $this->output = $this->config['output'];
    }

    /**
     * Check if this state is transient (has @always transitions that will immediately exit).
     */
    protected function isTransient(): bool
    {
        if (!isset($this->config['on'])) {
            return false;
        }

        foreach ($this->config['on'] as $eventType => $transitionConfig) {
            if ($eventType === '@always') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this state is inside a parallel region (not the parallel state itself).
     */
    protected function isInsideParallelRegion(): bool
    {
        $ancestor = $this->parent;

        while ($ancestor instanceof self) {
            if (isset($ancestor->type) && $ancestor->type === StateDefinitionType::PARALLEL) {
                return true;
            }

            // Also check config directly (type may not be initialized yet during construction)
            if (isset($ancestor->config['type']) && $ancestor->config['type'] === 'parallel') {
                return true;
            }

            $ancestor = $ancestor->parent;
        }

        return false;
    }

    /**
     * Create transition definitions for a given state definition.
     *
     * This method processes the 'on' configuration of the state definition, creating
     * corresponding {@see TransitionDefinition} objects for
     * each event.
     *
     * @param  StateDefinition  $stateDefinition  The state definition to process.
     *
     * @return array<string, TransitionDefinition>|null An array of TransitionDefinition objects, keyed by event names.
     */
    protected function createTransitionDefinitions(StateDefinition $stateDefinition): ?array
    {
        /** @var null|array<string, TransitionDefinition> $transitions */
        $transitions = null;

        if (
            !isset($stateDefinition->config['on']) ||
            !is_array($stateDefinition->config['on'])
        ) {
            return $transitions;
        }

        foreach ($stateDefinition->config['on'] as $eventName => $transitionConfig) {
            if (is_subclass_of($eventName, EventBehavior::class)) {
                $this->machine->behavior[BehaviorType::Event->value][$eventName::getType()] = $eventName;

                $eventName = $eventName::getType();
            }

            $transitions[$eventName] = new TransitionDefinition(
                transitionConfig: $transitionConfig,
                source: $this,
                event: $eventName,
            );
        }

        return $transitions;
    }

    /**
     * Finds the initial `StateDefinition` based on the `initial`
     * configuration key or the first state definition found.
     *
     * For parallel states, returns the parallel state itself since all regions
     * are entered simultaneously. Use findAllInitialStateDefinitions() to get
     * the initial states of all regions.
     *
     * @return StateDefinition|null The `StateDefinition` object for the initial state or `null` if not found.
     */
    public function findInitialStateDefinition(): ?StateDefinition
    {
        // Parallel states return themselves as the initial state
        // (all regions are entered simultaneously via enterParallelState)
        if ($this->type === StateDefinitionType::PARALLEL) {
            return $this;
        }

        // Try to find the initial state definition key in the configuration.
        // If not found, try to find the first state definition key.
        $initialStateDefinitionKey = $this->config['initial']
            ?? array_key_first($this->stateDefinitions ?? [])
            ?? null;

        // If there is no initial state definition key, then this root state definition is the initial state.
        if ($initialStateDefinitionKey === null) {
            return $this->order === 0 ? $this : null;
        }

        // The initial state definition key is built by concatenating the root state definition id
        $initialStateDefinitionKey = $this->id.$this->machine->delimiter.$initialStateDefinitionKey;

        // Try to find the initial state definition in the machine's id map.
        /** @var StateDefinition $initialStateDefinition */
        $initialStateDefinition = $this->machine->idMap[$initialStateDefinitionKey] ?? null;

        if ($initialStateDefinition === null) {
            return null;
        }

        // If the initial state definition has child state definitions,
        // then try to find it recursively from child state definitions.
        return (
            is_array($initialStateDefinition->stateDefinitions) &&
            count($initialStateDefinition->stateDefinitions) > 0
        )
            ? $initialStateDefinition->findInitialStateDefinition()
            : $initialStateDefinition;
    }

    /**
     * Finds all initial state definitions for parallel states.
     *
     * For parallel states, all regions are entered simultaneously, so this returns
     * the initial state of each region.
     *
     * @return array<StateDefinition> An array of initial state definitions for all regions.
     */
    public function findAllInitialStateDefinitions(): array
    {
        $initialStates = [];

        // If this is not a parallel state, find the initial state and drill down if needed
        if ($this->type !== StateDefinitionType::PARALLEL) {
            $initial = $this->findInitialStateDefinition();
            if ($initial instanceof self) {
                // If the initial state is itself a parallel state, recursively find its initials
                if ($initial->type === StateDefinitionType::PARALLEL) {
                    return $initial->findAllInitialStateDefinitions();
                }
                $initialStates[] = $initial;
            }

            return $initialStates;
        }

        // For parallel states, find the initial state of each region
        if ($this->stateDefinitions !== null) {
            foreach ($this->stateDefinitions as $region) {
                $regionInitials = $region->findAllInitialStateDefinitions();
                $initialStates  = array_merge($initialStates, $regionInitials);
            }
        }

        return $initialStates;
    }

    /**
     * Initialize the entry action/s for this state definition.
     */
    protected function initializeEntryActions(): void
    {
        if (isset($this->config['entry'])) {
            $this->entry = is_array($this->config['entry'])
                ? $this->config['entry']
                : [$this->config['entry']];
        } else {
            $this->entry = [];
        }

        $this->validateActionTuples($this->entry, 'actions (entry)');
    }

    /**
     * Initialize the exit action/s for this state definition.
     */
    protected function initializeExitActions(): void
    {
        if (isset($this->config['exit'])) {
            $this->exit = is_array($this->config['exit'])
                ? $this->config['exit']
                : [$this->config['exit']];
        } else {
            $this->exit = [];
        }

        $this->validateActionTuples($this->exit, 'actions (exit)');
    }

    /**
     * Validate action tuples eagerly at definition time.
     *
     * Catches misuse of framework-reserved @-prefixed keys (e.g. `@queue`) in state
     * entry/exit action lists, where they have no effect and would otherwise be silently
     * dropped at runtime. Mirrors the validation already performed for transition
     * actions/guards/calculators in TransitionDefinition / TransitionBranch.
     *
     * @param  array<int, mixed>  $actions
     */
    protected function validateActionTuples(array $actions, string $context): void
    {
        foreach ($actions as $action) {
            if (is_array($action)) {
                BehaviorTupleParser::parse($action, $context);
            }
        }
    }

    /**
     * Initialize machine invoke definition from the `machine` config key.
     *
     * Parses the state-level `machine` key and creates a MachineInvokeDefinition
     * with context transfer (`with`), forwarding (`forward`), and async (`queue`) config.
     */
    protected function initializeMachineInvoke(): void
    {
        $hasMachine = isset($this->config['machine']);
        $hasJob     = isset($this->config['job']);

        if (!$hasMachine && !$hasJob) {
            return;
        }

        $input      = $this->config['input'] ?? null;
        $rawForward = $this->config['forward'] ?? [];
        $rawQueue   = $this->config['queue'] ?? null;

        // Normalize forward entries: resolve FQCN class references to SCREAMING_SNAKE event types.
        // Consistent with endpoints, schedules, machineIdFor, and modelFor which all use resolveEventType().
        $forward = [];

        foreach ($rawForward as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // Format 1: plain — 'PROVIDE_CARD' or ProvideCardEvent::class
                $forward[] = EndpointDefinition::resolveEventType($value);
            } elseif (is_string($key) && is_string($value)) {
                // Format 2: rename — 'CANCEL_ORDER' => 'ABORT' or FQCN => FQCN
                $resolvedKey   = EndpointDefinition::resolveEventType($key);
                $resolvedValue = EndpointDefinition::resolveEventType($value);

                $forward[$resolvedKey] = $resolvedValue;
            } elseif (is_string($key) && is_array($value)) {
                // Format 3: full config — resolve key and child_event inside
                $resolvedKey = EndpointDefinition::resolveEventType($key);

                if (isset($value['child_event'])) {
                    $value['child_event'] = EndpointDefinition::resolveEventType($value['child_event']);
                }

                $forward[$resolvedKey] = $value;
            } else {
                // Pass through unknown formats (validator will catch them)
                $forward[$key] = $value;
            }
        }
        $rawConnection = $this->config['connection'] ?? null;
        $timeout       = isset($this->config['@timeout']) ? ($this->config['@timeout']['timeout'] ?? null) : null;
        $rawRetry      = $this->config['retry'] ?? null;

        // Normalize queue config: true → default, string → queue name, array → detailed
        $async      = $rawQueue !== null;
        $queue      = null;
        $connection = $rawConnection;
        $retry      = $rawRetry;

        if (is_string($rawQueue)) {
            $queue = $rawQueue;
        } elseif (is_array($rawQueue)) {
            $queue      = $rawQueue['queue'] ?? null;
            $connection = $rawQueue['connection'] ?? $connection;
            $retry      = $rawQueue['retry'] ?? $retry;
        }

        // Job actors are always async (they dispatch Laravel jobs)
        if ($hasJob) {
            $async = true;
        }

        $this->machineInvokeDefinition = new MachineInvokeDefinition(
            machineClass: $hasMachine ? $this->config['machine'] : '',
            input: $input,
            forward: $forward,
            async: $async,
            queue: $queue,
            connection: $connection,
            timeout: $timeout,
            retry: $retry,
            jobClass: $hasJob ? $this->config['job'] : null,
            target: $this->config['target'] ?? null,
        );
    }

    /**
     * Get the type of the state definition.
     *
     * @return StateDefinitionType The type of the state definition.
     */
    public function getStateDefinitionType(): StateDefinitionType
    {
        if (isset($this->config['type']) && $this->config['type'] === 'final') {
            if ($this->stateDefinitions !== null) {
                throw InvalidStateConfigException::finalStateCannotHaveChildStates($this->id);
            }

            return StateDefinitionType::FINAL;
        }

        if (isset($this->config['type']) && $this->config['type'] === 'parallel') {
            if ($this->stateDefinitions === null) {
                throw InvalidParallelStateDefinitionException::requiresChildStates($this->id);
            }

            return StateDefinitionType::PARALLEL;
        }

        if ($this->stateDefinitions === null) {
            return StateDefinitionType::ATOMIC;
        }

        return StateDefinitionType::COMPOUND;
    }

    // endregion

    // region Public Methods

    /**
     * Initialize the transitions for the current state and its child states.
     */
    public function initializeTransitions(): void
    {
        $this->transitionDefinitions = $this->createTransitionDefinitions($this);

        if (isset($this->config['@done'])) {
            $this->onDoneTransition = new TransitionDefinition(
                transitionConfig: $this->config['@done'],
                source: $this,
                event: '@done',
            );
        }

        if (isset($this->config['@fail'])) {
            $this->onFailTransition = new TransitionDefinition(
                transitionConfig: $this->config['@fail'],
                source: $this,
                event: '@fail',
            );
        }

        if (isset($this->config['@timeout'])) {
            $timeoutConfig = $this->config['@timeout'];

            // @timeout may contain both a 'target' for transition and a 'timeout' for duration
            $this->onTimeoutTransition = new TransitionDefinition(
                transitionConfig: $timeoutConfig,
                source: $this,
                event: '@timeout',
            );
        }

        // Parse @done.{finalState} keys for per-final-state routing
        foreach ($this->config ?? [] as $key => $value) {
            if (str_starts_with((string) $key, '@done.')) {
                $finalStateName = substr((string) $key, 6); // after '@done.'

                $this->onDoneStateTransitions[$finalStateName] = new TransitionDefinition(
                    transitionConfig: $value,
                    source: $this,
                    event: '@done.'.$finalStateName,
                );
            }
        }

        if ($this->stateDefinitions !== null) {
            /** @var StateDefinition $state */
            foreach ($this->stateDefinitions as $state) {
                $state->initializeTransitions();
            }
        }
    }

    /**
     * Initialize and return the events for the current state and its child states.
     * This method ensures that each event name is unique.
     *
     * @return array<string>|null An array of unique event names.
     */
    public function collectUniqueEvents(): ?array
    {
        // Initialize an empty array to store unique event names
        $events = [];

        // If there are transitions defined for the current state definition,
        // add the event names to the events array.
        if (isset($this->config['on']) && is_array($this->config['on'])) {
            foreach (array_keys($this->config['on']) as $eventName) {
                if (is_subclass_of($eventName, EventBehavior::class)) {
                    $eventName = $eventName::getType();
                }

                // Only add the event name if it hasn't been added yet
                if (!in_array($eventName, $events, true)) {
                    $events[] = $eventName;
                }
            }
        }

        // If there are child states, process them recursively and
        // add their event names to the events array.
        if ($this->stateDefinitions !== null) {
            /** @var StateDefinition $state */
            foreach ($this->stateDefinitions as $state) {
                // Get the events from the child state definition.
                $childEvents = $state->collectUniqueEvents();

                // Add the events from the child state to the events array, ensuring uniqueness
                if ($childEvents !== null) {
                    foreach ($childEvents as $eventName) {
                        if (!in_array($eventName, $events, true)) {
                            $events[] = $eventName;
                        }
                    }
                }
            }
        }

        // Return the array of unique event names
        return $events === [] ? null : $events;
    }

    /**
     * Check if this state delegates to a child machine.
     */
    public function hasMachineInvoke(): bool
    {
        return $this->machineInvokeDefinition instanceof MachineInvokeDefinition;
    }

    /**
     * Get the machine invoke definition for this state.
     */
    public function getMachineInvokeDefinition(): ?MachineInvokeDefinition
    {
        return $this->machineInvokeDefinition;
    }

    /**
     * Runs the exit actions of the current state definition with the given event.
     */
    public function runExitActions(State $state): void
    {
        // In parallel states, $state->currentStateDefinition points to the parallel ancestor,
        // not the region's atomic state. Use $this->route for the actual state being exited.
        // In non-parallel states, $state->currentStateDefinition IS the correct leaf state.
        $route = $state->isInParallelState() ? $this->route : $state->currentStateDefinition->route;

        // Record state exit start event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_EXIT_START,
            placeholder: $route,
        );

        foreach ($this->exit as $action) {
            $this->machine->runAction(
                actionDefinition: $action,
                state: $state,
                eventBehavior: $state->currentEventBehavior
            );
        }

        // Record state exit finish event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_EXIT_FINISH,
            placeholder: $route,
        );
    }

    /**
     * Runs the entry actions of the current state definition with the given event.
     *
     * @param  EventBehavior|null  $eventBehavior  The event to be processed.
     */
    public function runEntryActions(State $state, ?EventBehavior $eventBehavior = null): void
    {
        // In parallel states, $state->currentStateDefinition points to the parallel ancestor,
        // not the region's atomic state. Use $this->route for the actual state being entered.
        $route = $state->isInParallelState() ? $this->route : $state->currentStateDefinition->route;

        // Record state entry start event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTRY_START,
            placeholder: $route,
        );

        foreach ($this->entry as $action) {
            $this->machine->runAction(
                actionDefinition: $action,
                state: $state,
                eventBehavior: $eventBehavior
            );
        }

        // Record state entry finish event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTRY_FINISH,
            placeholder: $route,
        );
    }

    // endregion
}

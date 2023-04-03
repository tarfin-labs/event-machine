<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use SplObjectStorage;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

class MachineDefinition
{
    // region Public Properties

    /** The default id for the root machine definition. */
    public const DEFAULT_ID = '(machine)';

    /** The default delimiter used for constructing the global id by concatenating state definition local IDs. */
    public const STATE_DELIMITER = '.';

    /** The root state definition for this machine definition. */
    public StateDefinition $root;

    /**
     * The map of state definitions to their ids.
     *
     * @var \SplObjectStorage<\Tarfinlabs\EventMachine\Definition\StateDefinition, string>
     */
    public SplObjectStorage $idMap;

    /**
     * The child state definitions of this state definition.
     *
     * @var array<\Tarfinlabs\EventMachine\Definition\StateDefinition>|null
     */
    public ?array $states = null;

    /**
     * The events that can be accepted by this machine definition.
     *
     * @var null|array<string>
     */
    public ?array $events = null;

    /**
     * The initial state definition for this machine definition.
     *
     * @var null|\Tarfinlabs\EventMachine\Definition\StateDefinition
     */
    public ?StateDefinition $initial = null;

    /**
     * The context manager for this machine definition.
     * This is the extended state.
     */
    public ContextManager $context;

    /** The initial state for this state definition. */
    public ?State $initialState;

    // endregion

    // region Constructor

    /**
     * Create a new machine definition with the given arguments.
     *
     * @param  array|null  $config     The raw configuration array used to create the machine definition.
     * @param  array|null  $behavior     The implementation of the machine behavior that defined in the machine definition.
     * @param  string  $id         The id of the machine.
     * @param  string|null  $version    The version of the machine.
     * @param  string  $delimiter  The string delimiter for serializing the path to a string.
     */
    private function __construct(
        public ?array $config,
        public ?array $behavior,
        public string $id,
        public ?string $version,
        public string $delimiter = self::STATE_DELIMITER,
    ) {
        $this->idMap = new SplObjectStorage();

        $this->root = $this->createRootStateDefinition($config);
        $this->root->initializeTransitions();

        $this->states = $this->root->states;
        $this->events = $this->root->events;

        $this->initial = $this->root->initial;

        $this->context = $this->initializeContext();

        $this->initialState = $this->buildInitialState();
    }

    // endregion

    // region Static Constructors

    /**
     * Define a new machine with the given configuration and behavior.
     *
     * @param  ?array  $config The raw configuration array used to create the machine.
     * @param  array|null  $behavior An array of behavior options.
     *
     * @return self The created machine definition.
     */
    public static function define(
        ?array $config = null,
        ?array $behavior = null,
    ): self {
        return new self(
            config: $config ?? null,
            behavior: $behavior ?? null,
            id: $config['id'] ?? self::DEFAULT_ID,
            version: $config['version'] ?? null,
            delimiter: $config['delimiter'] ?? self::STATE_DELIMITER,
        );
    }

    // endregion

    // region Protected Methods

    /**
     * Initialize the root state definition for this machine definition.
     *
     *
     *
     * @return \Tarfinlabs\EventMachine\Definition\StateDefinition
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
     * Build the initial state for the machine.
     *
     * @return ?State The initial state of the machine.
     */
    protected function buildInitialState(): ?State
    {
        if (is_null($this->initial)) {
            return null;
        }

        return new State(
            activeStateDefinition: $this->initial,
            contextData: $this->context->toArray(),
        );
    }

    /**
     * Selects the first eligible transition while evaluating guard conditions.
     *
     * This method iterates through the given transition candidates and
     * checks if the guard conditions are met. If a candidate transition
     * does not have any guard conditions, it is considered eligible.
     * If a transition with guard conditions has all its guards evaluated
     * to true, it is considered eligible. The method returns the first
     * eligible transition encountered or null if none is found.
     *
     * @param  array|TransitionDefinition  $transitionCandidates Array of
     *        transition candidates or a single candidate to be checked.
     * @param  array  $event The event data used to evaluate guards.
     *
     * @return TransitionDefinition|null The first eligible transition or
     *         null if no eligible transition is found.
     */
    protected function selectFirstEligibleTransitionEvaluatingGuards(
        array|TransitionDefinition $transitionCandidates,
        array $event
    ): ?TransitionDefinition {
        $transitionCandidates = is_array($transitionCandidates)
            ? $transitionCandidates
            : [$transitionCandidates];

        /** @var \Tarfinlabs\EventMachine\Definition\TransitionDefinition $transitionCandidate */
        foreach ($transitionCandidates as $transitionCandidate) {
            if (!isset($transitionCandidate->conditions)) {
                return $transitionCandidate;
            }

            $conditionsMet = true;
            foreach ($transitionCandidate->conditions as $condition) {
                $guardBehavior = $this->getInvokableBehavior(behaviorDefinition:$condition, behaviorType: BehaviorType::Guard);

                if ($guardBehavior($this->context, $event) !== true) {
                    $conditionsMet = false;
                    break;
                }
            }

            if ($conditionsMet === true) {
                return $transitionCandidate;
            }
        }

        return null;
    }

    /**
     * Builds the current state of the state machine.
     *
     * This method creates a new State object, populating it with
     * the active state definition and the current context data.
     * If no current state is provided, the initial state is used.
     *
     * @param  StateDefinition|null  $currentStateDefinition The current state definition, if any.
     *
     * @return State The constructed State object representing the current state.
     */
    protected function buildCurrentState(StateDefinition $currentStateDefinition = null): State
    {
        return new State(
            activeStateDefinition: $currentStateDefinition ?? $this->initial,
            contextData:  $this->context->toArray(),
        );
    }

    /**
     * Get the current state definition.
     *
     * If a `State` object is passed, return its active state definition.
     * Otherwise, lookup the state in the `MachineDefinition` states array.
     * If the state is not found, return the initial state.
     *
     * @param  string|State|null  $state The state to retrieve the definition for.
     *
     * @return mixed The state definition.
     */
    protected function getCurrentStateDefinition(string|State|null $state): mixed
    {
        return $state instanceof State
            ? $state->activeStateDefinition
            : $this->states[$state] ?? $this->initial;
    }

    /**
     * Apply context data to the state if needed.
     *
     * If the given state is an instance of `State`, this method will apply the state's
     * `contextData` to the machine's context.
     *
     * @param  string|State|null  $state The state or state identifier to apply the context data from.
     */
    protected function applyContextDataIfNeeded(string|State|null $state): void
    {
        if ($state instanceof State) {
            $this->context->applyContextData($state->contextData);
        }
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
    protected function initializeContext(): ContextManager
    {
        // No context defined
        if (!isset($this->config['context'])) {
            return new ContextManager();
        }

        // Context defined as an array inside machine config
        if (is_array($this->config['context'])) {
            return new ContextManager(data: $this->config['context']);
        }

        // Context defined as a class name
        /** @var ContextManager $contextManager */
        $contextManager = new $this->config['context'];

        return $contextManager;
    }

    /**
     * Retrieve an invokable behavior instance or callable.
     *
     * This method checks if the given behavior definition is a valid class and a
     * subclass of InvokableBehavior. If not, it looks up the behavior in the
     * provided behavior type map. If the behavior is still not found, it returns
     * null.
     *
     * @param  string  $behaviorDefinition The behavior definition to look up.
     * @param  BehaviorType  $behaviorType The type of the behavior (e.g., guard or action).
     *
     * @return callable|null The invokable behavior instance or callable, or null if not found.
     */
    protected function getInvokableBehavior(string $behaviorDefinition, BehaviorType $behaviorType): ?callable
    {
        // If the guard definition is an invokable GuardBehavior, create a new instance.
        if (class_exists($behaviorDefinition) && is_subclass_of($behaviorDefinition, InvokableBehavior::class)) {
            /* @var callable $behaviorDefinition */
            return new $behaviorDefinition();
        }

        // If the guard definition is defined in the machine behavior, retrieve it.
        $invokableBehavior = $this->behavior[$behaviorType->value][$behaviorDefinition] ?? null;

        // If the retrieved behavior is not null and not callable, create a new instance.
        if ($invokableBehavior !== null && !is_callable($invokableBehavior)) {
            /** @var InvokableBehavior $invokableInstance */
            $invokableInstance = new $invokableBehavior();

            return $invokableInstance;
        }

        // Return the guard behavior, either a callable or null.
        return $invokableBehavior;
    }

    // endregion

    // region Public Methods

    /**
     * Transition the state machine to a new state based on an event.
     *
     * @param  State|string|null  $state The current state or state name, or null to use the initial state.
     * @param  array  $event The event that triggers the transition.
     *
     * @return State The new state after the transition.
     */
    public function transition(null|string|State $state, array $event): State
    {
        $currentStateDefinition = $this->getCurrentStateDefinition($state);

        $this->applyContextDataIfNeeded($state);

        // Find the transition definition for the event type
        /** @var null|\Tarfinlabs\EventMachine\Definition\TransitionDefinition $transitionDefinition */
        $transitionDefinition = $currentStateDefinition->transitions[$event['type']] ?? null;

        // If the transition definition is an array, find the transition candidate
        $transitionDefinition = $this->selectFirstEligibleTransitionEvaluatingGuards(
            transitionCandidates: $transitionDefinition,
            event: $event,
        );

        // If the transition definition is not found, do nothing
        if ($transitionDefinition === null) {
            return $this->buildCurrentState($currentStateDefinition);
        }

        // Run exit actions on the source/current state definition
        $transitionDefinition->source->runExitActions($event);

        // Run transition actions on the transition definition
        $transitionDefinition->runActions($event);

        // Run entry actions on the target state definition
        $transitionDefinition->target?->runEntryActions($event);

        return new State(
            activeStateDefinition: $transitionDefinition->target ?? $currentStateDefinition,
            contextData:  $this->context->toArray()
        );
    }

    /**
     * Executes the action associated with the provided action definition.
     *
     * This method retrieves the appropriate action behavior based on the
     * action definition, and if the action behavior is callable, it
     * executes it using the context and event data.
     *
     * @param  string  $actionDefinition The action definition, either a class
     *                                 name or an array key.
     * @param  array|null  $event The event data (optional).
     */
    public function runAction(string $actionDefinition, ?array $event = null): void
    {
        // Retrieve the appropriate action behavior based on the action definition.
        $actionBehavior = $this->getInvokableBehavior(behaviorDefinition: $actionDefinition, behaviorType: BehaviorType::Action);

        // If the action behavior is callable, execute it with the context and event data.
        if (is_callable($actionBehavior)) {
            $actionBehavior($this->context, $event);
        }
    }

    // endregion
}
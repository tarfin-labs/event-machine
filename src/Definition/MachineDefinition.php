<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use SplObjectStorage;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
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
            behavior: array_merge(self::initializeEmptyBehavior(), $behavior ?? []),
            id: $config['id'] ?? self::DEFAULT_ID,
            version: $config['version'] ?? null,
            delimiter: $config['delimiter'] ?? self::STATE_DELIMITER,
        );
    }

    // endregion

    // region Protected Methods

    /**
     * Initializes an empty behavior array with empty events, actions and guards arrays.
     *
     * @return array  An empty behavior array with empty events, actions and guards arrays.
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
     * Initialize the root state definition for this machine definition.
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
    public function getInitialState(): ?State
    {
        $initialStateDefinition = $this->root->findInitialStateDefinition();

        if (is_null($this->initial)) {
            return null;
        }

        $context = $this->initializeContextFromState();

        // Run entry actions on the initial state definition
        $this->initial->runEntryActions(context: $context);

        $initialState = new State(
            activeStateDefinition: $this->initial,
            context: $context,
        );

        if ($initialStateDefinition->transitions !== null) {
            foreach ($initialStateDefinition->transitions as $transition) {
                if ($transition->type === TransitionType::Always) {
                    return $this->transition(state: $initialState, event: ['type' => TransitionType::Always->value]);
                }
            }
        }

        return $initialState;
    }

    /**
     * Selects the first eligible transition while evaluating guards.
     *
     * This method iterates through the given transition candidates and
     * checks if all the guards are passed. If a candidate transition
     * does not have any guards, it is considered eligible.
     * If a transition with guards has all its guards evaluated
     * to true, it is considered eligible. The method returns the first
     * eligible transition encountered or null if none is found.
     *
     * @param  array|TransitionDefinition  $transitionCandidates  Array of
     *        transition candidates or a single candidate to be checked.
     * @param  EventBehavior  $eventBehavior         The event used to evaluate guards.
     *
     * @return TransitionDefinition|null The first eligible transition or
     *         null if no eligible transition is found.
     */
    protected function selectFirstEligibleTransitionEvaluatingGuards(
        array|TransitionDefinition $transitionCandidates,
        EventBehavior $eventBehavior,
        ContextManager $context,
    ): ?TransitionDefinition {
        $transitionCandidates = is_array($transitionCandidates)
            ? $transitionCandidates
            : [$transitionCandidates];

        /** @var \Tarfinlabs\EventMachine\Definition\TransitionDefinition $transitionCandidate */
        foreach ($transitionCandidates as $transitionCandidate) {
            if (!isset($transitionCandidate->guards)) {
                return $transitionCandidate;
            }

            $guardsPassed = true;
            foreach ($transitionCandidate->guards as $guard) {
                $guardBehavior = $this->getInvokableBehavior(behaviorDefinition: $guard, behaviorType: BehaviorType::Guard);

                if ($guardBehavior($context, $eventBehavior) !== true) {
                    $guardsPassed = false;
                    break;
                }
            }

            if ($guardsPassed === true) {
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
            activeStateDefinition: $currentStateDefinition ?? $this->initial,
            context: $context,
            eventBehavior: $eventBehavior,
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
        if (!is_null($state)) {
            return $state->context;
        }

        if (empty($this->behavior['context'])) {
            $contextConfig = $this->config['context'] ?? [];

            return ContextManager::validateAndCreate(['data' => $contextConfig]);
        }

        /** @var ContextManager $contextClass */
        $contextClass = $this->behavior['context'];

        return $contextClass::validateAndCreate($this->config['context'] ?? []);
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
        if (is_subclass_of($behaviorDefinition, InvokableBehavior::class)) {
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

    /**
     * Initialize an EventDefinition instance from the given event.
     *
     * If the $event argument is already an EventDefinition instance,
     * return it directly. Otherwise, create an EventDefinition instance
     * by invoking the behavior for the corresponding event type. If no
     * behavior is defined for the event type, a default EventDefinition
     * instance is returned.
     *
     * @param  EventBehavior|array  $event The event to initialize.
     * @param  StateDefinition  $stateDefinition The state definition to use.
     *
     * @return EventBehavior The initialized EventBehavior instance.
     */
    protected function initializeEvent(EventBehavior|array $event, StateDefinition $stateDefinition): EventBehavior
    {
        if ($event instanceof EventBehavior) {
            return $event;
        }

        if (isset($stateDefinition->machine->behavior[BehaviorType::Event->value][$event['type']])) {
            $eventDefinitionClass = $stateDefinition->machine->behavior[BehaviorType::Event->value][$event['type']];

            return $eventDefinitionClass::from($event);
        }

        return EventDefinition::from($event);
    }

    // endregion

    // region Public Methods

    /**
     * Transition the state machine to a new state based on an event.
     *
     * @param  State|null  $state The current state or state name, or null to use the initial state.
     * @param  EventBehavior|array  $event The event that triggers the transition.
     *
     * @return State The new state after the transition.
     */
    public function transition(null|State $state, EventBehavior|array $event): State
    {
        if ($state === null) {
            $state = $this->getInitialState();
        }

        $context = $state?->context ?? $this->initializeContextFromState($state);

        $currentStateDefinition = $this->getCurrentStateDefinition($state);

        $eventBehavior = $this->initializeEvent($event, $currentStateDefinition);

        // Find the transition definition for the event type
        /** @var null|array|\Tarfinlabs\EventMachine\Definition\TransitionDefinition $transitionDefinition */
        $transitionDefinition = $currentStateDefinition->transitions[$eventBehavior->type] ?? null;

        // If the transition definition is an array, find the transition candidate
        if (is_array($transitionDefinition)) {
            $transitionDefinition = $this->selectFirstEligibleTransitionEvaluatingGuards(
                transitionCandidates: $transitionDefinition,
                eventBehavior: $eventBehavior,
                context: $context,
            );
        }

        $transitionDefinition = $this->selectFirstEligibleTransitionEvaluatingGuards(
            transitionCandidates: $transitionDefinition,
            eventBehavior: $eventBehavior,
            context: $context,
        );

        // If the transition definition is not found, do nothing
        if ($transitionDefinition === null) {
            return $this->buildCurrentState($context, $currentStateDefinition, $eventBehavior);
        }

        // Run exit actions on the source/current state definition
        $transitionDefinition->source->runExitActions($context, $eventBehavior);

        // Run transition actions on the transition definition
        $transitionDefinition->runActions($context, $eventBehavior);

        // Run entry actions on the target state definition
        $transitionDefinition->target?->runEntryActions($context, $eventBehavior);

        $newState = new State(
            activeStateDefinition: $transitionDefinition->target ?? $currentStateDefinition,
            context: $context
        );

        if ($this->states[$newState->activeStateDefinition->key]->transitions !== null) {
            // Check if the new state has any @always transitions
            /** @var TransitionDefinition $transition */
            foreach ($this->states[$newState->activeStateDefinition->key]->transitions as $transition) {
                if ($transition->type === TransitionType::Always) {
                    return $this->transition(state: $newState, event: ['type' => TransitionType::Always->value]);
                }
            }
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
     * @param  string  $actionDefinition      The action definition, either a class
     * @param  \Tarfinlabs\EventMachine\ContextManager  $context
     *                                                                                      name or an array key.
     * @param  EventBehavior|null  $eventBehavior         The event (optional).
     */
    public function runAction(
        string $actionDefinition,
        ContextManager $context,
        ?EventBehavior $eventBehavior = null
    ): void {
        // Retrieve the appropriate action behavior based on the action definition.
        $actionBehavior = $this->getInvokableBehavior(behaviorDefinition: $actionDefinition, behaviorType: BehaviorType::Action);

        // If the action behavior is callable, execute it with the context and event payload.
        if (is_callable($actionBehavior)) {
            // Execute the action behavior.
            $actionBehavior($context, $eventBehavior);

            // Validate the context after the action is executed.
            $context->selfValidate();
        }
    }

    // endregion
}

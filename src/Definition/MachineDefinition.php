<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use ReflectionFunction;
use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;
use Tarfinlabs\EventMachine\Exceptions\InvalidFinalStateDefinitionException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;

class MachineDefinition
{
    // region Public Properties

    /** The default id for the root machine definition. */
    public const DEFAULT_ID = 'machine';

    /** The default delimiter used for constructing the global id by concatenating state definition local IDs. */
    public const STATE_DELIMITER = '.';

    /** The root state definition for this machine definition. */
    public StateDefinition $root;

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
        public ?array $scenarios,
        public string $delimiter = self::STATE_DELIMITER,
    ) {
        $this->scenariosEnabled = isset($this->config['scenarios_enabled']) && $this->config['scenarios_enabled'] === true;

        $this->root = $this->createRootStateDefinition($config);

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
        array $config = null,
        array $behavior = null,
        array $scenarios = null,
    ): self {
        return new self(
            config: $config ?? null,
            behavior: array_merge(self::initializeEmptyBehavior(), $behavior ?? []),
            id: $config['id'] ?? self::DEFAULT_ID,
            version: $config['version'] ?? null,
            scenarios: $scenarios,
            delimiter: $config['delimiter'] ?? self::STATE_DELIMITER,
        );
    }

    // endregion

    // region Protected Methods

    /**
     * Initializes an empty behavior array with empty events, actions and guard arrays.
     *
     * @return array  An empty behavior array with empty events, actions and guard arrays.
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
     * @param  array|null  $config The configuration for the root state definition.
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
        if (!empty($this->scenarios)) {
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
     * @return ?State The initial state of the machine.
     */
    public function getInitialState(EventBehavior|array $event = null): ?State
    {
        if (is_null($this->initialStateDefinition)) {
            return null;
        }

        $context = $this->initializeContextFromState();

        $initialState = $this->buildCurrentState(
            context: $context,
            currentStateDefinition: $this->initialStateDefinition,
        );

        $initialState                 = $this->getScenarioStateIfAvailable(state: $initialState, eventBehavior: $eventBehavior ?? null);
        $this->initialStateDefinition = $initialState->currentStateDefinition;

        // Record the internal machine init event.
        $initialState->setInternalEventBehavior(type: InternalEvent::MACHINE_START);

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

        if ($this->initialStateDefinition?->transitionDefinitions !== null) {
            foreach ($this->initialStateDefinition->transitionDefinitions as $transition) {
                if ($transition->isAlways === true) {
                    return $this->transition(
                        event: [
                            'type'  => TransitionProperty::Always->value,
                            'actor' => $initialState->currentEventBehavior->actor($context),
                        ],
                        state: $initialState
                    );
                }
            }
        }

        if ($this->eventQueue->isNotEmpty()) {
            $firstEvent = $this->eventQueue->shift();

            $eventBehavior = $this->initializeEvent($firstEvent, $initialState);

            return $this->transition($eventBehavior, $initialState);
        }

        // Record the machine finish event if the initial state is a final state.
        if ($initialState->currentStateDefinition->type === StateDefinitionType::FINAL) {
            $initialState->setInternalEventBehavior(
                type: InternalEvent::MACHINE_FINISH,
                placeholder: $initialState->currentStateDefinition->route
            );
        }

        return $initialState;
    }

    /**
     * Retrieves the scenario state if scenario is enabled and available; otherwise, returns the current state.
     *
     * @param  State  $state          The current state.
     * @param  EventBehavior|array|null  $eventBehavior The optional event behavior or event data.
     *
     * @return State|null The scenario state if scenario is enabled and found, otherwise returns the current state.
     */
    public function getScenarioStateIfAvailable(State $state, EventBehavior|array $eventBehavior = null): ?State
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
        if ($state->context->has('scenarioType') && isset($this->idMap[$scenarioStateKey])) {
            return $this->buildCurrentState(
                context: $state->context,
                currentStateDefinition: $this->idMap[$scenarioStateKey],
                eventBehavior: $eventBehavior
            );
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
        StateDefinition $currentStateDefinition = null,
        EventBehavior $eventBehavior = null,
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
     * @param  string|State|null  $state The state to retrieve the definition for.
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
    public function initializeContextFromState(State $state = null): ContextManager
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
     * @param  string  $behaviorDefinition The behavior definition to look up.
     * @param  BehaviorType  $behaviorType The type of the behavior (e.g., guard or action).
     *
     * @return callable|null The invokable behavior instance or callable, or null if not found.
     */
    public function getInvokableBehavior(string $behaviorDefinition, BehaviorType $behaviorType): ?callable
    {
        // If the guard definition is an invokable GuardBehavior, create a new instance.
        if (is_subclass_of($behaviorDefinition, InvokableBehavior::class)) {
            /* @var callable $behaviorDefinition */
            return new $behaviorDefinition($this->eventQueue);
        }

        // If the guard definition is defined in the machine behavior, retrieve it.
        $invokableBehavior = $this->behavior[$behaviorType->value][$behaviorDefinition] ?? null;

        // If the retrieved behavior is not null and not callable, create a new instance.
        if ($invokableBehavior !== null && !is_callable($invokableBehavior)) {
            /** @var InvokableBehavior $invokableInstance */
            $invokableInstance = new $invokableBehavior($this->eventQueue);

            return $invokableInstance;
        }

        if ($invokableBehavior === null) {
            throw BehaviorNotFoundException::build($behaviorDefinition);
        }

        return $invokableBehavior;
    }

    /**
     * Initialize an EventDefinition instance from the given event and state.
     *
     * If the $event argument is already an EventDefinition instance,
     * return it directly. Otherwise, create an EventDefinition instance
     * by invoking the behavior for the corresponding event type in the given
     * state. If no behavior is defined for the event type, a default
     * EventDefinition instance is returned.
     *
     * @param  EventBehavior|array  $event The event to initialize.
     * @param  State  $state The state in which the event is occurring.
     *
     * @return EventBehavior The initialized EventBehavior instance.
     */
    protected function initializeEvent(
        EventBehavior|array $event,
        State $state
    ): EventBehavior {
        if ($event instanceof EventBehavior) {
            return $event;
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
     * Retrieves the nearest `StateDefinition` by string.
     *
     * @param  string  $stateDefinitionId The state string.
     *
     * @return StateDefinition|null The nearest StateDefinition or null if it is not found.
     */
    public function getNearestStateDefinitionByString(string $stateDefinitionId): ?StateDefinition
    {
        if (empty($stateDefinitionId)) {
            return null;
        }

        $stateDefinitionId = $this->id.$this->delimiter.$stateDefinitionId;

        return $this->idMap[$stateDefinitionId] ?? null;
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
     * @param  \Tarfinlabs\EventMachine\Definition\StateDefinition  $currentStateDefinition The current state definition.
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior  $eventBehavior The event behavior.
     * @param  string|null  $firstStateDefinitionId The ID of the first state definition encountered during recursion.
     *
     * @return \Tarfinlabs\EventMachine\Definition\TransitionDefinition|null  The found transition definition, or null if none is found.
     *
     * @throws \Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException  If no transition definition is found for the event type.
     */
    protected function findTransitionDefinition(
        StateDefinition $currentStateDefinition,
        EventBehavior $eventBehavior,
        string $firstStateDefinitionId = null,
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

    // endregion

    // region Public Methods

    /**
     * Transition the state machine to a new state based on an event.
     *
     * @param  EventBehavior|array  $event The event that triggers the transition.
     * @param  State|null  $state The current state or state name, or null to use the initial state.
     *
     * @return State The new state after the transition.
     */
    public function transition(
        EventBehavior|array $event,
        State $state = null
    ): State {
        if ($state !== null) {
            $state = $this->getScenarioStateIfAvailable(state: $state, eventBehavior: $event);
        } else {
            // Use the initial state if no state is provided
            $state = $this->getInitialState(event: $event);
        }

        $currentStateDefinition = $this->getCurrentStateDefinition($state);

        // Initialize the event and validate it
        $eventBehavior = $this->initializeEvent($event, $state);
        $eventBehavior->selfValidate();

        $state->setCurrentEventBehavior($eventBehavior);

        /**
         * Get the transition definition for the current event type.
         *
         * @var null|array|TransitionDefinition $transitionDefinition
         */
        $transitionDefinition = $this->findTransitionDefinition($currentStateDefinition, $eventBehavior);

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
        if ($transitionBranch === null) {
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

        // Execute exit actions for the current state definition
        $transitionBranch->transitionDefinition->source->runExitActions($state);

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
        if ($state->currentStateDefinition->id !== $newState->currentStateDefinition->id) {
            $targetStateDefinition = $newState->currentStateDefinition;
        }

        // Record state enter event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_ENTER,
            placeholder: $state->currentStateDefinition->route,
        );

        // Execute entry actions for the new state definition
        $targetStateDefinition?->runEntryActions($newState, $eventBehavior);

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
                        state: $newState
                    );
                }
            }
        }

        // If there are events in the queue, process the first event
        if ($this->eventQueue->isNotEmpty()) {
            $firstEvent = $this->eventQueue->shift();

            $eventBehavior = $this->initializeEvent($firstEvent, $newState);

            return $this->transition($eventBehavior, $newState);
        }

        // Record the machine finish event if the initial state is a final state.
        if ($state->currentStateDefinition->type === StateDefinitionType::FINAL) {
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
     * @param string $actionDefinition The action definition, either a class
     * @param EventBehavior|null $eventBehavior The event (optional).
     * @throws \ReflectionException
     */
    public function runAction(
        string $actionDefinition,
        State $state,
        EventBehavior $eventBehavior = null
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

        if ($actionBehavior instanceof InvokableBehavior) {
            $actionBehavior->validateRequiredContext($state->context);
        }

        // Get the number of events in the queue before the action is executed.
        $numberOfEventsInQueue = $this->eventQueue->count();

        $actionBehaviorParameters = [];
        /** @var \ReflectionParameter $parameter */
        foreach ((new ReflectionFunction($actionBehavior))->getParameters() as $parameter) {
            $value = match ($parameter->getType()->getName()) {
                ContextManager::class => $state->context,
                default               => null,
            };
            $actionBehaviorParameters[$parameter->getName()] = $value;
        }

        // Execute the action behavior.
        $actionBehavior(...$actionBehaviorParameters);

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

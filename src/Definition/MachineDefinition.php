<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

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

    /** The initial state definition for this machine definition. */
    public ?StateDefinition $initialStateDefinition = null;

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
        $this->root = $this->createRootStateDefinition($config);
        $this->root->initializeTransitions();

        $this->stateDefinitions = $this->root->stateDefinitions;
        $this->events           = $this->root->events;

        $this->initialStateDefinition = $this->root->initialStateDefinition;
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

        if (is_null($this->initialStateDefinition)) {
            return null;
        }

        $context = $this->initializeContextFromState();

        $initialState = $this->buildCurrentState(
            context: $context,
            currentStateDefinition: $this->initialStateDefinition,
        );

        // Record the internal machine init event.
        $initialState->setInternalEventBehavior(type: InternalEvent::MACHINE_INIT);

        // Run entry actions on the initial state definition
        $this->initialStateDefinition->runEntryActions(state: $initialState);

        if ($initialStateDefinition->transitionDefinitions !== null) {
            foreach ($initialStateDefinition->transitionDefinitions as $transition) {
                if ($transition->type === TransitionType::Always) {
                    return $this->transition(
                        state: $initialState,
                        event: [
                            'type' => TransitionType::Always->value,
                        ]
                    );
                }
            }
        }

        return $initialState;
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
     *
     * @throws BehaviorNotFoundException
     */
    public function getInvokableBehavior(string $behaviorDefinition, BehaviorType $behaviorType): ?callable
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

        if ($invokableBehavior === null) {
            throw BehaviorNotFoundException::build($behaviorDefinition);
        }

        // Return the guard behavior, either a callable or null.
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

    public function getNearestStateDefinitionByString(string $state): ?StateDefinition
    {
        if (empty($state)) {
            return null;
        }

        $state = $this->id.$this->delimiter.$state;

        return $this->idMap[$state] ?? null;
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
     *
     * @throws BehaviorNotFoundException
     */
    public function transition(null|State $state, EventBehavior|array $event): State
    {
        // If the state is not passed, use the initial state
        $state ??= $this->getInitialState();

        $currentStateDefinition = $this->getCurrentStateDefinition($state);

        $eventBehavior = $this->initializeEvent($event, $state);

        // Set event behavior
        $state->setCurrentEventBehavior($eventBehavior);

        // Find the transition definition for the event type
        /** @var null|array|TransitionDefinition $transitionDefinition */
        $transitionDefinition = $currentStateDefinition->transitionDefinitions[$eventBehavior->type] ?? null;

        $transitionBranch = $transitionDefinition->getFirstValidTransitionBranch(
            eventBehavior: $eventBehavior,
            state: $state
        );

        // If the transition branch is not found, do nothing
        if ($transitionBranch === null) {
            return $state->setCurrentStateDefinition($currentStateDefinition);
        }

        // Run exit actions on the source/current state definition
        $transitionBranch->transitionDefinition->source->runExitActions($state);

        // Run transition actions on the transition definition
        $transitionBranch->runActions($state, $eventBehavior);

        // Run entry actions on the target state definition
        $transitionBranch->target?->runEntryActions($state, $eventBehavior);

        $newState = $state
            ->setCurrentStateDefinition($transitionBranch->target ?? $currentStateDefinition);

        if ($this->idMap[$newState->currentStateDefinition->id]->transitionDefinitions !== null) {
            // Check if the new state has any @always transitions
            /** @var TransitionDefinition $transition */
            foreach ($this->stateDefinitions[$newState->currentStateDefinition->key]->transitionDefinitions as $transition) {
                if ($transition->type === TransitionType::Always) {
                    return $this->transition(
                        state: $newState,
                        event: [
                            'type' => TransitionType::Always->value,
                        ]
                    );
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
     * @param  string  $actionDefinition The action definition, either a class
     * @param  EventBehavior|null  $eventBehavior The event (optional).
     *
     * @throws BehaviorNotFoundException
     */
    public function runAction(
        string $actionDefinition,
        State $state,
        ?EventBehavior $eventBehavior = null
    ): void {
        // Retrieve the appropriate action behavior based on the action definition.
        $actionBehavior = $this->getInvokableBehavior(behaviorDefinition: $actionDefinition, behaviorType: BehaviorType::Action);

        // If the action behavior is callable, execute it with the context and event payload.
        if (is_callable($actionBehavior)) {
            // Record the internal action init event.
            $state->setInternalEventBehavior(
                type: InternalEvent::ACTION_INIT,
                placeholder: $actionDefinition
            );

            // Execute the action behavior.
            $actionBehavior($state->context, $eventBehavior);

            // Validate the context after the action is executed.
            $state->context->selfValidate();

            // Record the internal action done event.
            $state->setInternalEventBehavior(
                type: InternalEvent::ACTION_DONE,
                placeholder: $actionDefinition
            );
        }
    }

    // endregion
}

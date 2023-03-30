<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use SplObjectStorage;

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
     * @var \SplObjectStorage<\Tarfinlabs\EventMachine\StateDefinition, string>
     */
    public SplObjectStorage $idMap;

    /**
     * The child state definitions of this state definition.
     *
     * @var null|array<\Tarfinlabs\EventMachine\StateDefinition>
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
     * @var null|\Tarfinlabs\EventMachine\StateDefinition
     */
    public ?StateDefinition $initial = null;

    /**
     * The context definition for this machine definition.
     * This is the extended state.
     */
    public ContextDefinition $context;

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

        $this->context = new ContextDefinition(data: $this->config['context'] ?? []);

        $this->initialState = $this->buildInitialState();
    }

    // endregion

    // region Protected Methods

    /**
     * Initialize the root state definition for this machine definition.
     *
     *
     * @return \Tarfinlabs\EventMachine\StateDefinition
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
        // Retrieve the current state definition from the state property
        $currentStateDefinition = $state instanceof State
            ? $state->activeStateDefinition
            : $this->states[$state] ?? $this->initial;

        // If this is a state instance, apply the context data to the context
        if ($state instanceof State) {
            $this->context->applyContextData($state->contextData);
        }

        // Find the transition definition for the event type
        /** @var null|\Tarfinlabs\EventMachine\TransitionDefinition $transitionDefinition */
        $transitionDefinition = $currentStateDefinition->transitions[$event['type']] ?? null;

        // If the transition definition is not found, do nothing
        if ($transitionDefinition === null) {
            return new State(
                activeStateDefinition: $currentStateDefinition,
                contextData:  $this->context->toArray(),
            );
        }

        // Run exit actions on the source/current state definition
        $transitionDefinition->source->runExitActions($event);

        // Execute the transition actions associated with the event type
        $transitionDefinition->runActions($event);

        // Run entry actions on the target state definition
        $transitionDefinition->target?->runEntryActions($event);

        return new State(
            activeStateDefinition: $transitionDefinition->target ?? $currentStateDefinition,
            contextData:  $this->context->toArray()
        );
    }

    /**
     * Executes the transition actions associated with the event type.
     *
     * If there are no transition actions associated with the event type, this method returns early.
     * Otherwise, it runs each action in the order they are defined, passing the `$event` argument to
     * the `runAction()` method of the `StateMachine` class for execution.
     *
     * @param  array|null  $event  The event to run the transition actions for.
     */
    public function runAction(string $action, ?array $event = null): void
    {
        $actionMethod = $this->behavior['actions'][$action] ?? null;

        if ($actionMethod !== null) {
            $actionMethod($this->context, $event);
        }
    }

    // endregion
}

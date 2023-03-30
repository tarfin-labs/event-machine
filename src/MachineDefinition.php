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
     * Define a new machine with the given configuration.
     *
     * @param  ?array  $config The raw configuration array used to create the machine.
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

    public function transition(null|string|State $state, array $event): State
    {
        // Retrieve the current state definition from the state property
        $currentStateDefinition = $state instanceof State
            ? $state->activeStateDefinition
            : $this->states[$state] ?? $this->initial;

        if ($state instanceof State) {
            $this->context->applyContextData($state->contextData);
        }

        // Find the transition definition for the event type
        $transitionDefinition = $currentStateDefinition->transitions[$event['type']] ?? null;

        // If the transition definition is not found, do nothing
        if ($transitionDefinition === null) {
            return new State(
                activeStateDefinition: $currentStateDefinition,
                contextData:  $this->context->toArray(),
            );
        }

        // Execute the transition actions associated with the event type
        $transitionDefinition->runActions($event);

        // Run entry actions on the target state definition
        $transitionDefinition->target?->runEntryActions($event);

        return new State(
            activeStateDefinition: $transitionDefinition->target ?? $currentStateDefinition,
            contextData:  $this->context->toArray()
        );
    }

    // endregion

    public function runAction(string $action, ?array $event = null): void
    {
        $actionMethod = $this->behavior['actions'][$action] ?? null;

        if ($actionMethod !== null) {
            $actionMethod($this->context, $event);
        }
    }
}

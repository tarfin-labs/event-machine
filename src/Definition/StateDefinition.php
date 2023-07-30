<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

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

    /**
     * The transition definitions of this state definition.
     *
     * @var null|array<\Tarfinlabs\EventMachine\Definition\TransitionDefinition>
     */
    public ?array $transitionDefinitions;

    /**
     * The events that can be accepted by this state definition.
     *
     * @var null|array<string>
     */
    public ?array $events = null;

    /** The initial state definition for this machine definition. */
    public ?StateDefinition $initialStateDefinition = null;

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
     * which will be returned in {@see \Tarfinlabs\EventMachine\Actor\State} instances.
     *
     * @var null|array<mixed>
     */
    public ?array $meta = null;

    // endregion

    // region Constructor

    /**
     * Create a new state definition with the given configuration and options.
     *
     * @param  ?array  $config The raw configuration array used to create the state definition.
     * @param  ?array  $options The options array for configuring the state definition.
     */
    public function __construct(
        public ?array $config,
        array $options = null,
    ) {
        $this->initializeOptions($options);

        $this->path        = $this->buildPath();
        $this->id          = $this->buildId();
        $this->description = $this->buildDescription();

        $this->order = count($this->machine->idMap);

        $this->machine->idMap[$this->id] = $this;

        $this->stateDefinitions = $this->createChildStateDefinitions();
        $this->events           = $this->collectUniqueEvents();

        $this->initialStateDefinition = $this->findInitialStateDefinition();

        $this->initializeEntryActions();
        $this->initializeExitActions();

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
        return $this->parent
            ? array_merge($this->parent->path, [$this->key])
            : [];
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
    protected function initializeOptions(?array $options): void
    {
        $this->parent  = $options['parent'] ?? null;
        $this->machine = $options['machine'] ?? null;
        $this->key     = $options['key'] ?? null;
    }

    /**
     * Create transition definitions for a given state definition.
     *
     * This method processes the 'on' configuration of the state definition, creating
     * corresponding {@see \Tarfinlabs\EventMachine\Definition\TransitionDefinition} objects for
     * each event.
     *
     * @param  StateDefinition  $stateDefinition The state definition to process.
     *
     * @return array|null An array of TransitionDefinition objects, keyed by event names.
     */
    protected function createTransitionDefinitions(StateDefinition $stateDefinition): ?array
    {
        /** @var null|array $transitions */
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
     * @return StateDefinition|null The `StateDefinition` object for the initial state or `null` if not found.
     */
    public function findInitialStateDefinition(): ?StateDefinition
    {
        $initialStateKey = $this->config['initial']
            ?? array_key_first($this->stateDefinitions ?? [])
            ?? null;

        if ($initialStateKey === null) {
            return null;
        }

        $initialStateKey = $this->id.$this->machine->delimiter.$initialStateKey;

        $initialStateDefinition = $this->machine->idMap[$initialStateKey] ?? null;

        if ($initialStateDefinition === null) {
            return null;
        }

        return is_array($initialStateDefinition->stateDefinitions) && count($initialStateDefinition->stateDefinitions) > 0
            ? $initialStateDefinition->findInitialStateDefinition()
            : $initialStateDefinition;
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
    }

    // endregion

    // region Public Methods

    /**
     * Initialize the transitions for the current state and its child states.
     */
    public function initializeTransitions(): void
    {
        $this->transitionDefinitions = $this->createTransitionDefinitions($this);

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
            foreach ($this->config['on'] as $eventName => $transitionConfig) {
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
     * Runs the exit actions of the current state definition with the given event.
     *
     * @throws \Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException
     * @throws \Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException
     */
    public function runExitActions(State $state): void
    {
        // Record state exit start event
        $state->setInternalEventBehavior(
            type: InternalEvent::STATE_EXIT_START,
            placeholder: $state->currentStateDefinition->key
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
            placeholder: $state->currentStateDefinition->key
        );
    }

    /**
     * Runs the entry actions of the current state definition with the given event.
     *
     * @param \Tarfinlabs\EventMachine\Behavior\EventBehavior|null $eventBehavior The event to be processed.
     *
     * @throws \Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException
     * @throws \Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException
     */
    public function runEntryActions(State $state, EventBehavior $eventBehavior = null): void
    {
        foreach ($this->entry as $action) {
            $this->machine->runAction(
                actionDefinition: $action,
                state: $state,
                eventBehavior: $eventBehavior
            );
        }
    }

    // endregion
}

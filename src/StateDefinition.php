<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

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
     * @var null|array<\Tarfinlabs\EventMachine\StateDefinition>
     */
    public ?array $states = null;

    /**
     * The transition definitions of this state definition.
     *
     * @var array<\Tarfinlabs\EventMachine\TransitionDefinition>
     */
    public array $transitions;

    /**
     * The events that can be accepted by this state definition.
     *
     * @var null|array<string>
     */
    public ?array $events = null;

    /**
     * The initial state definition for this machine definition.
     *
     * @var null|\Tarfinlabs\EventMachine\StateDefinition
     */
    public ?StateDefinition $initialState = null;

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
        ?array $options = null,
    ) {
        $this->initializeOptions($options);

        $this->path        = $this->buildPath();
        $this->id          = $this->buildId();
        $this->description = $this->buildDescription();

        $this->order = $this->machine->idMap->count();
        $this->machine->idMap->attach($this, $this->id);

        $this->states = $this->createChildStates();
        $this->events = $this->collectUniqueEvents();

        $this->initialState = $this->findInitialState();
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
     * @return ?array<\Tarfinlabs\EventMachine\StateDefinition> An array of child state definitions or null if no child states are defined.
     */
    protected function createChildStates(): ?array
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
     * Formats the transitions for a given state definition.
     *
     * This method extracts the transition configurations from the given state definition's
     * config and creates a new instance of TransitionDefinition for each transition.
     * The resulting array of TransitionDefinition instances is indexed by the corresponding event names.
     *
     * @param  StateDefinition  $stateDefinition The state definition for which to format the transitions.
     *
     * @return array An array of TransitionDefinition instances indexed by event names.
     */
    protected function formatTransitions(StateDefinition $stateDefinition): array
    {
        $transitions = [];

        if (
            !isset($stateDefinition->config['on']) ||
            !is_array($stateDefinition->config['on'])
        ) {
            return $transitions;
        }

        foreach ($stateDefinition->config['on'] as $eventName => $transitionConfig) {
            $transitions[$eventName] = new TransitionDefinition(
                transitionConfig: $transitionConfig,
                source: $this,
                event: $eventName,
            );
        }

        return $transitions;
    }

    protected function findInitialState(): ?StateDefinition
    {
        $initialStateKey = $this->config['initial']
            ?? array_key_first($this->states ?? [])
            ?? null;

        if (!isset($initialStateKey)) {
            return null;
        }

        if (!isset($this->states[$initialStateKey])) {
            return null;
        }

        return $this->states[$initialStateKey];
    }

    // endregion

    // region Public Methods

    /**
     * Initialize the transitions for the current state and its child states.
     */
    public function initializeTransitions(): void
    {
        $this->transitions = $this->formatTransitions($this);

        if ($this->states !== null) {
            foreach ($this->states as $state) {
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
                // Only add the event name if it hasn't been added yet
                if (!in_array($eventName, $events, true)) {
                    $events[] = $eventName;
                }
            }
        }

        // If there are child states, process them recursively and
        // add their event names to the events array.
        if ($this->states !== null) {
            /** @var \Tarfinlabs\EventMachine\StateDefinition $state */
            foreach ($this->states as $state) {
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

    // endregion
}

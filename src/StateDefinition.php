<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class StateDefinition
{
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

        $this->path = $this->initializePath();
        $this->id   = $this->initializeId();

        $this->description = $this->config['description'] ?? null;

        $this->order = $this->machine->idMap->count();
        $this->machine->idMap->attach($this, $this->id);

        $this->states = $this->initializeStates();
    }

    /**
     * Initialize the path for this state definition by appending its key to the parent's path.
     *
     * @return array<string> The path for this state definition.
     */
    protected function initializePath(): array
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
    protected function initializeId(): string
    {
        return $this->config['id'] ?? implode($this->machine->delimiter, array_merge([$this->machine->id], $this->path));
    }

    /**
     * Initialize the child state definitions for this state definition by iterating through
     * the 'states' configuration and creating new StateDefinition instances.
     *
     * @return ?array<\Tarfinlabs\EventMachine\StateDefinition> An array of child state definitions or null if no child states are defined.
     */
    protected function initializeStates(): ?array
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
}

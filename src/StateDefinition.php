<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class StateDefinition
{
    /** The root machine definition this state definition belongs to. */
    public MachineDefinition $machine;

    /** The parent state definition. */
    public ?StateDefinition $parent;

    /** The local id of the state definition, representing its location in the overall state value. */
    public ?string $localId;

    /** The unique global id of the state definition. */
    public string $globalId;

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
        $this->parent  = $options['parent'] ?? null;
        $this->machine = $options['machine'] ?? null;
        $this->localId = $options['local_id'] ?? null;

        $this->path = $this->parent
            ? array_merge($this->parent->path, [$this->localId])
            : [];

        $this->description = $this->config['description'] ?? null;

        // Assign the global ID to either the 'id' value from the config,
        // or generate a unique ID by merging the machine ID with
        // the path, separated by the machine delimiter.
        // TODO: Extract this to a method.
        $this->globalId = $this->config['id'] ?? implode($this->machine->delimiter, array_merge([$this->machine->name], $this->path));

        $this->order = $this->machine->idMap->count();
        $this->machine->idMap->attach($this, $this->globalId);

        $this->states = $this->initializeStates();
    }

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
                    'local_id' => $stateName,
                ]
            );
        }

        return $states;
    }
}

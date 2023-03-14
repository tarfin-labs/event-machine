<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class State
{
    public const DEFAULT_NAME = 'machine';

    public ?State $machine = null;
    public ?string $path   = null;

    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?int $version = 1,
        public string|int|null $value = null,
        public State|string|null $parent = null,
        public State|string|null $initialState = null,
        public array|null $states = null,
    ) {
        $this->initialize();
    }

    protected function initialize(): void
    {
        $this->initializeMachine();
        $this->initializeName();
        $this->initializeValue();
        $this->initializeVersion();
        $this->initializeDescription();
        $this->initializePath();
        $this->initializeStates();
        $this->initializeInitialState();
    }

    /**
     * Set the machine to the parent's machine if the parent
     * exists, otherwise set it to this State instance.
     */
    protected function initializeMachine(): void
    {
        $this->machine = $this->parent !== null
            ? $this->parent->machine
            : $this;
    }

    /**
     * Initializes the name property with a default value if not provided.
     *
     * If the name is not defined or is empty, it will be set to the default name.
     */
    protected function initializeName(): void
    {
        $this->name = !empty($this->name)
            ? $this->name
            : self::DEFAULT_NAME;
    }

    /**
     * Initializes the value property with the name property if not provided.
     *
     * If the value is not defined, it will be set to the name property.
     */
    protected function initializeValue(): void
    {
        $this->value = $this->value ?? $this->name;
    }

    /**
     * Initializes the version property with a default value if not valid.
     *
     * If the version is less than 1, it will be set to the default value of 1.
     */
    protected function initializeVersion(): void
    {
        $this->version = $this->version >= 1
            ? $this->version
            : 1;
    }

    /**
     * Initializes the description property with a default value if not provided.
     *
     * If the description is not defined, it will be set to null.
     */
    protected function initializeDescription(): void
    {
        $this->description = $this->description ?? null;
    }

    /**
     * Initializes the path property based on the parent and name properties.
     *
     * If a parent exists, the path is set to the parent's path concatenated
     * with the current state's name. Otherwise, the path is set to the
     * current state's name.
     */
    protected function initializePath(): void
    {
        $this->path = $this->parent
            ? $this->parent->path.'.'.$this->name
            : $this->name;
    }

    /**
     * Initializes the states property by creating State instances for each item.
     *
     * Iterates through the provided states and initializes a new State instance for
     * each one. If the item is a string, it is treated as the name of the state.
     * If the item is an array, it is treated as the state definition.
     */
    protected function initializeStates(): void
    {
        if (!is_null($this->states)) {
            foreach ($this->states as $key => $state) {
                // If it is only has a state name, initialize a state using that name
                if (is_string($state)) {
                    unset($this->states[$key]);
                    $this->states[$state] = Machine::define([
                        'name'   => $state,
                        'parent' => $this,
                    ]);

                    continue;
                }

                // If it is an array, initialize a state using that array state definition
                $this->states[$key] = Machine::define(
                    $state + [
                        'name'   => $key,
                        'parent' => $this,
                    ]
                );
            }
        }
    }

    /**
     * Initializes the initialState property by creating a State instance if provided.
     *
     * If the initialState is provided, it creates a new State instance using the
     * initialState as the name and the current state as the parent.
     */
    protected function initializeInitialState(): void
    {
        if (!empty($this->initialState)) {
            $this->initialState = Machine::define([
                'name'   => $this->initialState,
                'parent' => $this,
            ]);
        }
    }
}

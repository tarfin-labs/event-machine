<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class State
{
    public const DEFAULT_NAME = 'machine';

    public ?State $machine = null;

    public function __construct(
        public ?string $name = null,
        public ?int $version = 1,
        public ?State $parent = null,
        public State|string|null $initialState = null,
        public string|int|null $value = null,
        public array|null $states = null,
    ) {
        // If parent machine is not defined, use this (State) as parent
        $this->machine = $this->parent ? $this->parent->machine : $this;

        // If name is not defined, use the default name
        $this->name = !empty($this->name) ? $this->name : self::DEFAULT_NAME;

        // If value is not defined, use name as value
        $this->value = $this->value ?? $this->name;

        // Initialize states
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

        // If initial state is not initialized, initialize it
        if (!empty($this->initialState)) {
            $this->initialState = Machine::define([
                'name'   => $this->initialState,
                'parent' => $this,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class State
{
    public const DEFAULT_NAME = 'machine';

    public ?State $machine = null;

    public function __construct(
        public ?string $name = null,
        public ?State $parent = null,
    ) {
        // If parent machine is not defined, use this (State) as parent
        $this->machine = $this->parent ? $this->parent->machine : $this;

        // If name is not defined, use the default name
        $this->name = !empty($this->name) ? $this->name : self::DEFAULT_NAME;
    }
}

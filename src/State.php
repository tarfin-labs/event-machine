<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class State
{
    public const DEFAULT_NAME = 'machine';

    public function __construct(
        public ?string $name = null,
    ) {
        // If name is not defined, use default name
        $this->name = !empty($this->name) ? $this->name : self::DEFAULT_NAME;
    }
}

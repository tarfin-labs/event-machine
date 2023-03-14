<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class State
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}

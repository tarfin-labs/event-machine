<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class StateDefinition
{
    public function __construct(
        public ?array $config,
    ) {
    }
}

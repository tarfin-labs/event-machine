<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class State
{
    public array $value;

    public function __construct(
        public StateDefinition $activeStateDefinition,
        public ?array $contextData = null,
    ) {
        $this->value = [$this->activeStateDefinition->key];
    }
}

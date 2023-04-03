<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Tarfinlabs\EventMachine\Definition\StateDefinition;

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
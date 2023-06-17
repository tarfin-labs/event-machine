<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\StateDefinition;

class State
{
    public array $value;

    public function __construct(
        public ContextManager $context,
        public ?StateDefinition $activeStateDefinition,
    ) {
        $this->value = [$this->activeStateDefinition->id];
    }
}

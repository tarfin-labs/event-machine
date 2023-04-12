<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class MachineActor
{
    public ?State $state = null;

    public function __construct(
        // TODO: Ability to pass a State object to start from a specific state and context
        public MachineDefinition $definition,
    ) {
        $this->state = $definition->initialState;
    }

    public function send(EventBehavior|array $event): State
    {
        return $this->definition->transition($this->state, $event);
    }
}

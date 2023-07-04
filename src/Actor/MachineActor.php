<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

class MachineActor
{
    /** The current state of the machine actor. */
    public ?State $state = null;

    public function __construct(
        public MachineDefinition $definition,
        ?State $state = null,
    ) {
        // If no state is provided, use the initial state of the machine.
        $this->state = $state ?? $this->definition->getInitialState();
    }

    /**
     * @throws BehaviorNotFoundException
     */
    public function send(EventBehavior|array $event): State
    {
        $this->state = $this->definition->transition($this->state, $event);

        return $this->state;
    }
}

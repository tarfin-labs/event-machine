<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class MachineActor
{
    /** The current state of the machine actor. */
    public ?State $state = null;

    /**
     * The context manager for the machine actor.
     *
     * This is the extended state.
     */
    public ContextManager $context;

    public function __construct(
        public MachineDefinition $definition,
        ?State $state = null,
    ) {
        $this->state = $state ?? $this->definition->getInitialState();

        $this->context = $this->definition->initializeContextFromState($state);
    }

    public function send(EventBehavior|array $event): State
    {
        return $this->definition->transition($this->state, $event);
    }
}

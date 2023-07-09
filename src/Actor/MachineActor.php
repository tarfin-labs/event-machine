<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

class MachineActor
{
    /** The current state of the machine actor. */
    public ?State $state = null;

    /**
     * @throws BehaviorNotFoundException
     */
    public function __construct(
        public MachineDefinition $definition,
        State|string|null $state = null,
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

    public function persist(): ?State
    {
        MachineEvent::insert(
            $this->state->history->map(fn (MachineEvent $machineEvent) => array_merge($machineEvent->toArray(), [
                'machine_value' => json_encode($machineEvent->machine_value, JSON_THROW_ON_ERROR),
                'payload'       => json_encode($machineEvent->payload, JSON_THROW_ON_ERROR),
                'context'       => json_encode($machineEvent->context, JSON_THROW_ON_ERROR),
                'meta'          => json_encode($machineEvent->meta, JSON_THROW_ON_ERROR),
            ]))->toArray()
        );

        return $this->state;
    }
}

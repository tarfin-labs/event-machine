<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Symfony\Component\Uid\Ulid;
use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Definition\SourceType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\InternalEvent;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;

class State
{
    public array $value;
    public Collection $history;

    public function __construct(
        public ContextManager $context,
        public ?StateDefinition $currentStateDefinition,
        public ?EventBehavior $eventBehavior = null,
    ) {
        $this->history = collect();

        $this->updateMachineValueFromState();

        if ($this->eventBehavior !== null) {
            $this->history[] = $this->eventBehavior;
        }
    }

    protected function updateMachineValueFromState(): void
    {
        $this->value = [$this->currentStateDefinition->id];
    }

    public function setCurrentStateDefinition(StateDefinition $stateDefinition): self
    {
        $this->currentStateDefinition = $stateDefinition;
        $this->updateMachineValueFromState();

        return $this;
    }

    public function setInternalEventBehavior(
        InternalEvent $type,
        ?string $placeholder = null,
    ): self {
        $type = ($placeholder === null)
            ? $type->value
            : sprintf($type->value, $placeholder);

        $eventDefinition = new EventDefinition(
            type: $type,
            source: SourceType::INTERNAL
        );

        return $this->setEventBehavior($eventDefinition);
    }

    public function setEventBehavior(EventBehavior $eventBehavior): self
    {
        $this->eventBehavior = $eventBehavior;

        $id    = Ulid::generate();
        $count = count($this->history) + 1;

        $this->history->push(new MachineEvent([
            'id'              => $id,
            'sequence_number' => $count,
            'created_at'      => now(),
            'machine_id'      => $this->currentStateDefinition->machine->id,
            'machine_value'   => [
                $this->currentStateDefinition->id,
            ],
            'root_event_id' => $count === 1 ? $id : $this->history[0]->id,
            'source'        => $eventBehavior->source,
            'type'          => $eventBehavior->type,
            'payload'       => $eventBehavior->payload,
            'version'       => $eventBehavior->version,
            'context'       => $this->context->data,
            'meta'          => $this->currentStateDefinition->meta,
        ]));

        return $this;
    }
}

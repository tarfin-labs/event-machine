<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Illuminate\Support\Str;
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

    public function __construct(
        public ContextManager $context,
        public ?StateDefinition $currentStateDefinition,
        public ?EventBehavior $currentEventBehavior = null,
        public ?Collection $history = null,
    ) {
        $this->history ??= (new MachineEvent())->newCollection();

        $this->updateMachineValueFromState();
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
        string $placeholder = null,
        array $payload = null,
    ): self {
        $type = ($placeholder === null)
            ? $type->value
            : sprintf($type->value, Str::of($placeholder)->classBasename()->camel());

        $eventDefinition = new EventDefinition(
            type: $type,
            payload: $payload,
            source: SourceType::INTERNAL,
        );

        return $this->setCurrentEventBehavior($eventDefinition);
    }

    public function setCurrentEventBehavior(EventBehavior $currentEventBehavior): self
    {
        $this->currentEventBehavior = $currentEventBehavior;

        $id    = Ulid::generate();
        $count = count($this->history) + 1;

        $this->history->push(new MachineEvent([
            'id'              => $id,
            'sequence_number' => $count,
            'created_at'      => now(),
            'machine_id'      => $this->currentStateDefinition->machine->id,
            'machine_value'   => [$this->currentStateDefinition->id],
            'root_event_id'   => $count === 1 ? $id : $this->history[0]->id,
            'source'          => $currentEventBehavior->source,
            'type'            => $currentEventBehavior->type,
            'payload'         => $currentEventBehavior->payload,
            'version'         => $currentEventBehavior->version,
            'context'         => $this->context->toArray(),
            'meta'            => $this->currentStateDefinition->meta,
        ]));

        return $this;
    }
}

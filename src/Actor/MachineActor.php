<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

class MachineActor
{
    /** The current state of the machine actor. */
    public ?State $state = null;

    /**
     * @throws BehaviorNotFoundException|RestoringStateException
     */
    public function __construct(
        public MachineDefinition $definition,
        State|string|null $state = null,
    ) {
        $this->state = match (true) {
            $state === null         => $this->definition->getInitialState(),
            $state instanceof State => $state,
            is_string($state)       => $this->restoreStateFromRootEventId($state),
        };
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

    // region Restoring State

    /**
     * @throws RestoringStateException
     */
    public function restoreStateFromRootEventId(string $key): State
    {
        $machineEvents = MachineEvent::query()
            ->where('root_event_id', $key)
            ->oldest('sequence_number')
            ->get();

        if ($machineEvents->isEmpty()) {
            throw RestoringStateException::build('Machine state not found.');
        }

        $this->state = new State(
            context: $this->restoreContext($machineEvents->last()->context),
            currentStateDefinition: 1,
            currentEventBehavior: 1,
            history: 1
        );

        return $this->state;
    }

    protected function restoreContext(array $persistedContext): ContextManager
    {
        if (!empty($this->definition->behavior['context'])) {
            /** @var ContextManager $contextClass */
            $contextClass = $this->definition->behavior['context'];

            return $contextClass::validateAndCreate($persistedContext);
        }

        return ContextManager::validateAndCreate(['data' => $persistedContext]);
    }

    // endregion
}

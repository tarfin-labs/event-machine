<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Actor;

use Stringable;
use JsonSerializable;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Definition\SourceType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;

class MachineActor implements JsonSerializable, Stringable
{
    /** The current state of the machine actor. */
    public ?State $state = null;

    /**
     * @throws BehaviorNotFoundException|RestoringStateException
     */
    public function __construct(
        public MachineDefinition $definition,
        State|string $state = null,
    ) {
        $this->state = match (true) {
            $state === null         => $this->definition->getInitialState(),
            $state instanceof State => $state,
            is_string($state)       => $this->restoreStateFromRootEventId($state),
        };
    }

    /**
     * Sends an event to the machine actor.
     *
     * @param  EventBehavior|array  $event The event to be sent.
     * @param  bool  $shouldPersist Whether to persist the state change.
     *
     * @return State The new state of the object after the transition.
     *
     * @throws \Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException
     */
    public function send(
        EventBehavior|array $event,
        bool $shouldPersist = true,
        bool $shouldThrowOnGuardFail = false,
    ): State {
        $this->state = $this->definition->transition($event, $this->state);

        if ($shouldPersist === true) {
            $this->persist();
        }

        if ($shouldThrowOnGuardFail === true) {
            $failedGuard = $this
                ->state
                ->history
                ->filter(fn ($item) => preg_match('/machine\.guard\..*\.fail/', $item['type']))
                ->last();

            if ($failedGuard !== null) {
                throw MachineValidationException::withMessages([
                    $failedGuard->type => $failedGuard->payload[$failedGuard->type],
                ]);
            }
        }

        return $this->state;
    }

    public function persist(): ?State
    {
        MachineEvent::upsert(
            values: $this->state->history->map(fn (MachineEvent $machineEvent) => array_merge($machineEvent->toArray(), [
                'created_at'    => $machineEvent->created_at->toDateTimeString(),
                'machine_value' => json_encode($machineEvent->machine_value, JSON_THROW_ON_ERROR),
                'payload'       => json_encode($machineEvent->payload, JSON_THROW_ON_ERROR),
                'context'       => json_encode($machineEvent->context, JSON_THROW_ON_ERROR),
                'meta'          => json_encode($machineEvent->meta, JSON_THROW_ON_ERROR),
            ]))->toArray(),
            uniqueBy: ['id']
        );

        return $this->state;
    }

    // region Restoring State

    /**
     * Restores the state of the machine from the given root event identifier.
     *
     * @param  string  $key The root event identifier to restore state from.
     *
     * @return State The restored state of the machine.
     *
     * @throws RestoringStateException If machine state is not found.
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

        $lastMachineEvent = $machineEvents->last();

        return new State(
            context: $this->restoreContext($lastMachineEvent->context),
            currentStateDefinition: $this->restoreCurrentStateDefinition($lastMachineEvent->machine_value),
            currentEventBehavior: $this->restoreCurrentEventBehavior($lastMachineEvent),
            history: $machineEvents,
        );
    }

    /**
     * Restores the context using the persisted context data.
     *
     * @param  array  $persistedContext The persisted context data.
     *
     * @return ContextManager The restored context manager instance.
     */
    protected function restoreContext(array $persistedContext): ContextManager
    {
        if (!empty($this->definition->behavior['context'])) {
            /** @var ContextManager $contextClass */
            $contextClass = $this->definition->behavior['context'];

            return $contextClass::validateAndCreate($persistedContext);
        }

        return ContextManager::validateAndCreate(['data' => $persistedContext]);
    }

    /**
     * Restores the current state definition based on the given machine value.
     *
     * @param  array  $machineValue The machine value containing the ID of the state definition
     *
     * @return StateDefinition The restored current state definition
     */
    protected function restoreCurrentStateDefinition(array $machineValue): StateDefinition
    {
        return $this->definition->idMap[$machineValue[0]];
    }

    /**
     * Restores the current event behavior based on the given MachineEvent.
     *
     * @param  MachineEvent  $machineEvent The MachineEvent object representing the event.
     *
     * @return EventDefinition The restored EventDefinition object.
     */
    protected function restoreCurrentEventBehavior(MachineEvent $machineEvent): EventDefinition
    {
        if ($machineEvent->source === SourceType::INTERNAL) {
            return EventDefinition::from([
                'type'    => $machineEvent->type,
                'payload' => $machineEvent->payload,
                'version' => $machineEvent->version,
                'source'  => SourceType::INTERNAL,
            ]);
        }

        if (isset($this->definition->behavior[BehaviorType::Event->value][$machineEvent->type])) {
            /** @var EventBehavior $eventDefinitionClass */
            $eventDefinitionClass = $this
                ->definition
                ->behavior[BehaviorType::Event->value][$machineEvent->type];

            return $eventDefinitionClass::validateAndCreate($machineEvent->payload);
        }

        return EventDefinition::from([
            'type'    => $machineEvent->type,
            'payload' => $machineEvent->payload,
            'version' => $machineEvent->version,
            'source'  => SourceType::EXTERNAL,
        ]);
    }

    // endregion

    /**
     * Returns the JSON serialized representation of the object.
     *
     * @return mixed The JSON serialized representation of the object.
     */
    public function jsonSerialize(): string
    {
        return $this->state->history->first()->root_event_id;
    }

    /**
     * Returns a string representation of the current object.
     *
     * @return string The string representation of the object.
     */
    public function __toString(): string
    {
        return $this->state->history->first()->root_event_id ?? '';
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use InvalidArgumentException;

class StateNode
{
    /** The parent state node. */
    public ?StateNode $parent = null;

    /** The root machine node.  */
    public EventMachine $machine;

    /**
     * The child state nodes.
     *
     * @var null|array<\Tarfinlabs\EventMachine\StateNode>
     */
    public ?array $states = null;

    /**
     * All the event types accepted by this state node and its descendants.
     *
     * @var array<string>
     */
    public array $events = [];

    /** The relative key of the state node, which represents its location in the overall state value. */
    public string $key;

    /** The unique ID of the state node  */
    public string $id;

    /** The type of this state node */
    public StateNodeType $type;

    /**
     * The string path from the root machine node to this node.
     *
     * @var array<string>
     */
    public array $path;

    /** @The order this state node appears. Corresponds to the implicit SCXML document order. */
    public int $order = -1;

    public ?string $description = null;

    public function __construct(
        public ?array $config = null,
        ?array $options = null,
    ) {
        $this->parent  = $options['_parent'] ?? null;
        $this->key     = $options['_key'];
        $this->machine = $options['_machine'];

        $this->path = $this->parent
            ? array_merge($this->parent->path, [$this->key])
            : [];

        $this->id = $this->config['id'] ??
            implode($this->machine->delimiter, array_merge([$this->machine->id], $this->path));

        $this->type = $this->config['type'] ??
            match (true) {
                isset($this->config['states']) && count(array_keys($this->config['states'])) > 0 => StateNodeType::COMPOUND,
                isset($this->config['history']) && $this->config['history']                      => StateNodeType::HISTORY,
                default                                                                          => StateNodeType::ATOMIC,
            };

        $this->description = $this->config['description'] ?? null;
        $this->order       = count($this->machine->idMap);

        $this->machine->idMap[$this->id] = $this;

        $this->states = isset($this->config['states'])
            ? $this->mapValues($this->config['states'], function ($stateConfig, $key) {
                return new StateNode($stateConfig, [
                    '_parent'  => $this,
                    '_key'     => $key,
                    '_machine' => $this->machine,
                ]);
            })
            : null;

        $this->validateCompoundStateInitial();

        $this->events = $this->getEvents();
    }

    protected function mapValues(array $collection, callable $iteratee): array
    {
        $result = [];

        foreach ($collection as $key => $value) {
            $result[$key] = $iteratee($value, $key, $collection);
        }

        return $result;
    }

    protected function validateCompoundStateInitial(): void
    {
        if ($this->type === StateNodeType::COMPOUND && !isset($this->config['initial'])) {
            $firstStateKey = array_keys($this->states)[0];

            throw new InvalidArgumentException(
                "No initial state specified for compound state node '#{$this->id}'. "
                ."Try adding [ 'initial' => '{$firstStateKey}' ] to the state config."
            );
        }
    }

    public function _initialize(): void
    {
    }

    protected function getEvents(): array
    {
        // TODO: Consider caching events

        $events = $this->ownEvents();

        if ($this->states === null) {
            return $events;
        }

        foreach ($this->states as $state) {
            foreach ($state->getEvents() as $event) {
                $events[] = $event;
            }
        }

        return array_values(array_unique($events));
    }

    protected function ownEvents(): array
    {
        $events = [];

        if (isset($this->config['on'])) {
            $events = array_merge($events, array_keys($this->config['on']));
        }

        if (isset($this->config['initial'])) {
            $initialState = $this->states[$this->config['initial']];

            $events = array_merge($events, $initialState->ownEvents());
        }

        return array_values(array_unique($events));
    }
}

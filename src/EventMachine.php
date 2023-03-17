<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class EventMachine
{
    public const STATE_DELIMITER = '.';

    public const MACHINE_ID = '(machine)';

    public string $id;
    public string $delimiter;
    public ?string $version = null;
    public StateNode $root;

    /** @var null|array<\Tarfinlabs\EventMachine\StateNode> */
    public ?array $states = null;

    /** @var array<string> */
    public array $events = [];

    /**
     * TODO: Consider SplObjectStorage?
     *
     * @var array<string, \Tarfinlabs\EventMachine\StateNode>
     */
    public array $idMap = [];

    public function __construct(
        public ?array $config = null,
    ) {
        $this->id        = $this->config['id'] ?? self::MACHINE_ID;
        $this->delimiter = $this->config['delimiter'] ?? self::STATE_DELIMITER;
        $this->version   = $this->config['version'] ?? null;

        $this->root = new StateNode(
            config: $this->config,
            options: [
                '_key'     => $this->id,
                '_machine' => $this,
            ],
        );

        $this->states = $this->root->states;
        $this->events = $this->root->events;
    }

    public function start(): State
    {
        // TODO:
        // - Run the machine
        // - Register event listeners to Laravel

        return new State();
    }
}

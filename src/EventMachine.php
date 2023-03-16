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

        $this->root->_initialize();

        $this->states = $this->root->states;
    }
}

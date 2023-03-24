<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use SplObjectStorage;

class MachineDefinition
{
    /** The default id for the root machine definition. */
    public const DEFAULT_ID = '(machine)';

    /** The default delimiter used for constructing the global id by concatenating state definition local IDs. */
    public const STATE_DELIMITER = '.';

    /** The root state definition for this machine definition. */
    public StateDefinition $root;

    /** The map of state definitions to their ids. */
    public SplObjectStorage $idMap;

    /**
     * The child state definitions of this state definition.
     *
     * @var null|array<\Tarfinlabs\EventMachine\StateDefinition>
     */
    public ?array $states = null;

    /**
     * @param  array|null  $config     The raw configuration array used to create the machine definition.
     * @param  string  $id         The id of the machine.
     * @param  string|null  $version    The version of the machine.
     * @param  string  $delimiter  The string delimiter for serializing the path to a string.
     */
    private function __construct(
        public ?array $config,
        public string $id,
        public ?string $version,
        public string $delimiter = self::STATE_DELIMITER,
    ) {
        $this->idMap  = new SplObjectStorage();
        $this->root   = $this->initializeRootStateDefinition($config);
        $this->states = $this->root->states;
    }

    /**
     * Define a new machine with the given configuration.
     *
     * @param  ?array  $config The raw configuration array used to create the machine.
     *
     * @return self The created machine definition.
     */
    public static function define(
        ?array $config = null,
    ): self {
        return new self(
            config: $config ?? null,
            id: $config['id'] ?? self::DEFAULT_ID,
            version: $config['version'] ?? null,
            delimiter: $config['delimiter'] ?? self::STATE_DELIMITER,
        );
    }

    /**
     * Initialize the root state definition for this machine definition.
     *
     *
     * @return \Tarfinlabs\EventMachine\StateDefinition
     */
    protected function initializeRootStateDefinition(?array $config): StateDefinition
    {
        return new StateDefinition(
            config: $config ?? null,
            options: [
                'machine' => $this,
                'key'     => $this->id,
            ]
        );
    }
}

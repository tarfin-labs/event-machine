<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class MachineDefinition
{
    /** The default name for the root machine definition. */
    public const DEFAULT_NAME = '(machine)';

    /** The default delimiter used for constructing the global id by concatenating state definition local IDs. */
    public const STATE_DELIMITER = '.';

    /** The root state definition for this machine definition. */
    public StateDefinition $root;

    /**
     * @param  array|null  $config The raw configuration array used to create the machine definition.
     * @param  string  $name The name of the machine.
     * @param  string|null  $version The version of the machine.
     * @param  string  $delimiter The string delimiter for serializing the path to a string.
     */
    private function __construct(
        public ?array $config,
        public string $name,
        public ?string $version,
        public string $delimiter = self::STATE_DELIMITER,
    ) {
        $this->root = new StateDefinition(
            config: $config ?? null,
            options: [
                'machine'  => $this,
                'local_id' => $this->name,
            ]
        );
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
            name: $config['name'] ?? self::DEFAULT_NAME,
            version: $config['version'] ?? null,
            delimiter: $config['delimiter'] ?? self::STATE_DELIMITER,
        );
    }
}

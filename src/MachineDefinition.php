<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class MachineDefinition
{
    public const DEFAULT_NAME = '(machine)';

    public const STATE_DELIMITER = '.';

    public StateDefinition $root;

    private function __construct(
        /** The raw config used to create the machine. */
        public ?array $config,
        public string $name,
        public ?string $version,
        /** The string delimiter for serializing the path to a string. */
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

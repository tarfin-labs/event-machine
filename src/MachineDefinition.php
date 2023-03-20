<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class MachineDefinition
{
    public const DEFAULT_NAME = '(machine)';

    private function __construct(
        public string $name,
    ) {
    }

    public static function define(
        ?array $config = null,
    ): self {
        return new self(
            name: $config['name'] ?? self::DEFAULT_NAME,
        );
    }
}

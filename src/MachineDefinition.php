<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class MachineDefinition
{
    public const DEFAULT_NAME = '(machine)';

    private function __construct(
        public ?array $config,
        public string $name,
        public ?string $version,
    ) {
    }

    public static function define(
        ?array $config = null,
    ): self {
        return new self(
            config: $config ?? null,
            name: $config['name'] ?? self::DEFAULT_NAME,
            version: $config['version'] ?? null,
        );
    }
}

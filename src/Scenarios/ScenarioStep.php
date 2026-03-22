<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

class ScenarioStep
{
    private function __construct(
        public readonly string $eventType,
        public readonly array $payload = [],
    ) {}

    public static function send(string $eventType, array $payload = []): self
    {
        return new self($eventType, $payload);
    }
}

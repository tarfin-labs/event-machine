<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

class ScenarioResult
{
    public function __construct(
        public readonly string $machineId,
        public readonly string $rootEventId,
        public readonly string $currentState,
        public readonly array $models,
        public readonly int $stepsExecuted,
        public readonly float $duration,
        public readonly array $childResults = [],
    ) {}
}

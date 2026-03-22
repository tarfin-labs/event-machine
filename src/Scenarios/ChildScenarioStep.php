<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

class ChildScenarioStep
{
    private ?string $scenarioClass = null;
    private array $params          = [];

    public function __construct(
        public readonly string $machineClass,
    ) {}

    public function scenario(string $scenarioClass): self
    {
        $this->scenarioClass = $scenarioClass;

        return $this;
    }

    /**
     * Pass parameters to the child scenario.
     */
    public function with(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    public function getScenarioClass(): ?string
    {
        return $this->scenarioClass;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}

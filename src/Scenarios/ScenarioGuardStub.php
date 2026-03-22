<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class ScenarioGuardStub extends GuardBehavior
{
    public function __construct(
        private readonly bool $returnValue,
        ?Collection $eventQueue = null,
    ) {
        parent::__construct($eventQueue);
    }

    public function __invoke(State $state): bool
    {
        return $this->returnValue;
    }
}

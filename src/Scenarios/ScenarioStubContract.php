<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Actor\State;

interface ScenarioStubContract
{
    /**
     * Apply stub data to the machine state instead of executing real logic.
     *
     * Implement this in action behaviors that have external dependencies
     * (API calls, service integrations) to provide a scenario-friendly
     * alternative that sets context values from predetermined data.
     */
    public function applyStub(State $state, array $data): void;
}

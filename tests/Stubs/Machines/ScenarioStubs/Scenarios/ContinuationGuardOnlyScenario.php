<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsValidGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * Continuation with only guard overrides (no delegation outcomes or @continue).
 *
 * Phase 1 (@start): idle → routing → processing(@done) → reviewing (target)
 * Phase 2 (continuation): only guard override for parallel @done — no @continue entries.
 *   Machine pauses at interactive states; guard applies when parallel state @done fires.
 */
class ContinuationGuardOnlyScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'reviewing';
    protected string $description = 'Guard-only continuation (no @continue)';

    protected function plan(): array
    {
        return [
            'processing' => '@done',
        ];
    }

    protected function continuation(): array
    {
        return [
            'parallel_check' => [
                IsValidGuard::class => true,
            ],
        ];
    }
}

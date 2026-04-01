<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsValidGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * Continuation with multiple interactive pauses.
 *
 * Phase 1 (@start): idle → routing → processing(@done) → reviewing (target)
 * Phase 2 (continuation):
 *   Request 2: reviewing → START_PARALLEL → parallel_check → @done → all_checked (interactive pause)
 *   Request 3: all_checked → FINISH → approved (final, deactivate)
 *
 * Tests that continuation persists across multiple requests.
 */
class MultiPauseContinuationScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'reviewing';
    protected string $description = 'Multi-pause: reviewing → all_checked → approved';

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

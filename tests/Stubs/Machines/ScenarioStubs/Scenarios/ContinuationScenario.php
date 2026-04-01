<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * Basic continuation test.
 *
 * Phase 1 (@start): idle → routing → processing(@done) → reviewing (target, interactive)
 * Phase 2 (continuation): reviewing → DELEGATE → delegating(@done) → delegation_complete (final)
 *
 * QA interacts at 'reviewing', then continuation auto-handles delegation.
 */
class ContinuationScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'reviewing';
    protected string $description = 'Start → reviewing, then continuation handles delegation';

    protected function plan(): array
    {
        return [
            'processing' => '@done',
        ];
    }

    protected function continuation(): array
    {
        return [
            'delegating' => '@done',
        ];
    }
}

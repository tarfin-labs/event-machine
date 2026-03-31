<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Outputs;

use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

/**
 * Simple output behavior for scenario override testing.
 */
class TestScenarioOutput extends OutputBehavior
{
    public function __invoke(): array
    {
        return ['original' => true, 'source' => 'real'];
    }
}

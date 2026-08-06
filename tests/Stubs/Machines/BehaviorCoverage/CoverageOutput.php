<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage;

use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class CoverageOutput extends OutputBehavior
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [];
    }
}

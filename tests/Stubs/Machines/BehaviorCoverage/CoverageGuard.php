<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class CoverageGuard extends GuardBehavior
{
    public function __invoke(): bool
    {
        return true;
    }
}

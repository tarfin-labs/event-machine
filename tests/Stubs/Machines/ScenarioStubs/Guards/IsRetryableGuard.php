<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsRetryableGuard extends GuardBehavior
{
    public function __invoke(): bool
    {
        return false;
    }
}

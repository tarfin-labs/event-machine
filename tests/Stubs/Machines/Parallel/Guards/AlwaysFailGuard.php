<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class AlwaysFailGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return false;
    }
}

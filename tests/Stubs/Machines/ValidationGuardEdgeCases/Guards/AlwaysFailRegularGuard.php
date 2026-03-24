<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class AlwaysFailRegularGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return false;
    }
}

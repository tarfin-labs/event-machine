<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class AlwaysPassRegularGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return true;
    }
}

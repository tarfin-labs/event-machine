<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsCountPositiveGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('count') > 0;
    }
}

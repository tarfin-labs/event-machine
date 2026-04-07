<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class MinimumGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('totalPrice') >= 100;
    }
}

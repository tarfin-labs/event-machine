<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsAboveThresholdGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, int $threshold): bool
    {
        return $context->get('amount') > $threshold;
    }
}

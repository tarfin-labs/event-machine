<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsAmountInRangeGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, int $min, int $max): bool
    {
        return $context->get('amount') >= $min
            && $context->get('amount') <= $max;
    }
}

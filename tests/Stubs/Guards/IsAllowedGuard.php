<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsAllowedGuard extends GuardBehavior
{
    public function __invoke(): bool
    {
        return true;
    }
}

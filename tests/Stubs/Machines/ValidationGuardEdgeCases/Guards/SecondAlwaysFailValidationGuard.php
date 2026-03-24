<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class SecondAlwaysFailValidationGuard extends ValidationGuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        $this->errorMessage = 'Second validation also fails.';

        return false;
    }
}

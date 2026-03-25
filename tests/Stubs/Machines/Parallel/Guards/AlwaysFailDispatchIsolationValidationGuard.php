<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class AlwaysFailDispatchIsolationValidationGuard extends ValidationGuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        $this->errorMessage = 'Guarded region rejects submission.';

        return false;
    }
}

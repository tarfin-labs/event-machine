<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class AlwaysFailParallelValidationGuard extends ValidationGuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        $this->errorMessage = 'Validation always fails in parallel.';

        return false;
    }
}

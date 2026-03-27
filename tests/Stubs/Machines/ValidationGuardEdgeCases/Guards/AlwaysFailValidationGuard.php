<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class AlwaysFailValidationGuard extends ValidationGuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        $this->errorMessage = 'Validation always fails.';

        return false;
    }
}

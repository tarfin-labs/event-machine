<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class TestAlwaysFailValidationGuard extends ValidationGuardBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        ?array $arguments = null,
    ): bool {
        $this->errorMessage = 'Validation always fails.';

        return false;
    }
}

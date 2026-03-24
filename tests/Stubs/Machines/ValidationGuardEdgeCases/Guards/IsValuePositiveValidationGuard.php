<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class IsValuePositiveValidationGuard extends ValidationGuardBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior): bool
    {
        $value = $eventBehavior->payload['value'] ?? 0;

        if ($value <= 0) {
            $this->errorMessage = 'Value must be positive.';

            return false;
        }

        return true;
    }
}

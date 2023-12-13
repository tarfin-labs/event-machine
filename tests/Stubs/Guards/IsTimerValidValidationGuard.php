<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class IsTimerValidValidationGuard extends ValidationGuardBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        ?array $arguments = null
    ): bool {
        $value  = $eventBehavior->payload['value'];
        $result = $value > (int) $arguments[0];

        if ($result === false) {
            $this->errorMessage = "Timer has a value of {$value}, must be greater than {$arguments[0]}.";
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

/**
 * Guard that captures event type into context and always passes.
 */
class CaptureGuardEventGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): bool
    {
        $context->set('guard_event_type', $event->type);

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Captures event type during init @always — event may be null.
 */
class CaptureInitEventAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, ?EventBehavior $event = null): void
    {
        $context->set('init_event_type', $event?->type);
    }
}

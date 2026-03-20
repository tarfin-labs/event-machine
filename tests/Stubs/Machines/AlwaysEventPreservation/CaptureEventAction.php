<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Captures injected event data into context for assertion.
 */
class CaptureEventAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $context->set('captured_event_type', $event->type);
        $context->set('captured_payload', $event->payload);
        $context->set('captured_actor', $event->actor($context));
    }
}

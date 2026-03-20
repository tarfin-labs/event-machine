<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Captures event data in @always action after compound @done for assertion.
 */
class CaptureDoneEventAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $context->set('done_event_type', $event->type);
        $context->set('done_event_payload', $event->payload);
    }
}

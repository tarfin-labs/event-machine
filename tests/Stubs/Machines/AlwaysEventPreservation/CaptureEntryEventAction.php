<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Captures event data in entry action for assertion.
 */
class CaptureEntryEventAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $context->set('entry_event_type', $event->type);
    }
}

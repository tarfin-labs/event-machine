<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Captures $state->currentEventBehavior->type into context for consistency testing.
 */
class CaptureCurrentEventBehaviorAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, State $state): void
    {
        $context->set('currentBehaviorType', $state->currentEventBehavior?->type);
    }
}

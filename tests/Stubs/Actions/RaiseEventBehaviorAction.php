<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

/**
 * Action that raises an EventBehavior instance (not a plain array).
 * Used for assertRaised/assertNotRaised regression test.
 */
class RaiseEventBehaviorAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->raise(EventDefinition::from([
            'type'    => 'INSTANCE_RAISED',
            'payload' => ['source' => 'event_behavior'],
        ]));
    }
}

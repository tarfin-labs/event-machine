<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Event with custom actor() override — returns a hardcoded value.
 */
class ActorOverrideEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'OVERRIDE_EVENT';
    }

    public function actor(ContextManager $context): mixed
    {
        return 'overridden_actor';
    }
}

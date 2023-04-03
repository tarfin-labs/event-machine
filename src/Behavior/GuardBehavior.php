<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

interface GuardBehavior extends InvokableBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior): bool;
}

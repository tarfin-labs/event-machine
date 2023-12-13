<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

abstract class ResultBehavior extends InvokableBehavior
{
    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        ?array $arguments = null,
    ): mixed;
}

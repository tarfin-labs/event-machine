<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

/**
 * ActionBehavior class.
 *
 * This abstract class extends the InvokableBehavior class. It provides a way to define action behaviors
 * that can be invoked within a specific context.
 */
abstract class ActionBehavior extends InvokableBehavior
{
    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null,
    );
}

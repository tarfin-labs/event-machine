<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

/**
 * ActionBehavior class.
 *
 * This abstract class extends the InvokableBehavior class. It provides a way to define action behaviors
 * that can be invoked within a specific context.
 */
abstract class ActionBehavior extends InvokableBehavior
{
    /**
     * Invokes the method with the given parameters.
     */
    public function __invoke(): void
    {
        parent::__invoke();
    }
}

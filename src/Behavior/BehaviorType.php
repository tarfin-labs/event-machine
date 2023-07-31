<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

/**
 * Class BehaviorType.
 *
 * The BehaviorType class represents an enumerated type for different types of behaviors.
 */
enum BehaviorType: string
{
    case Guard   = 'guards';
    case Action  = 'actions';
    case Event   = 'events';
    case Context = 'context';

    /**
     * Returns the behavior class based on the current value of $this.
     *
     * @return string The class name of the behavior.
     */
    public function getBehaviorClass(): string
    {
        return match ($this) {
            self::Guard   => GuardBehavior::class,
            self::Action  => ActionBehavior::class,
            self::Event   => EventBehavior::class,
            self::Context => ContextManager::class,
        };
    }
}

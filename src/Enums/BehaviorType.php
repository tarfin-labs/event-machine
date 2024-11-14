<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Enums;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\ResultBehavior;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

/**
 * Class BehaviorType.
 *
 * The BehaviorType class represents an enumerated type for different types of behaviors.
 */
enum BehaviorType: string
{
    case Calculator = 'calculators';
    case Guard      = 'guards';
    case Action     = 'actions';
    case Result     = 'results';
    case Event      = 'events';
    case Context    = 'context';

    /**
     * Returns the behavior class based on the current value of $this.
     *
     * @return string The class name of the behavior.
     */
    public function getBehaviorClass(): string
    {
        return match ($this) {
            self::Calculator => CalculatorBehavior::class,
            self::Guard      => GuardBehavior::class,
            self::Action     => ActionBehavior::class,
            self::Result     => ResultBehavior::class,
            self::Event      => EventBehavior::class,
            self::Context    => ContextManager::class,
        };
    }
}

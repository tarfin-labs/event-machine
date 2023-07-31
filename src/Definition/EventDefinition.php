<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Class EventDefinition.
 *
 * This class is an implementation of the EventBehavior class and represents a definition of an event.
 */
class EventDefinition extends EventBehavior
{
    /**
     * Returns the type of the given method.
     *
     * @return string The type of the method.
     */
    public static function getType(): string
    {
        return '(event)';
    }
}

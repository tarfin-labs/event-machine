<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

/**
 * Class GuardBehavior.
 *
 * This is an abstract class that extends InvokableBehavior and provides the base structure for guard behavior classes.
 * Guards are used in event-driven systems to determine whether an event should be allowed to proceed or not.
 */
abstract class GuardBehavior extends InvokableBehavior
{
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * No `Event` suffix. Exercises the branch of EventBehavior::getType() where beforeLast('Event')
 * finds nothing and returns the basename unchanged. Named, not anonymous: an anonymous class's
 * basename is `class@anonymous...`, which cannot reach this branch's real output.
 */
class OrderSubmitted extends EventBehavior {}

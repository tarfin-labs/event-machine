<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Named exactly `Event`, so beforeLast('Event') yields an empty string. Exercises the fallback
 * in EventBehavior::getType() that returns the full basename when stripping empties it.
 */
class Event extends EventBehavior {}

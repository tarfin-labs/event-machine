<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Carries `Event` both in the middle and as the suffix, so it pins that getType() strips only
 * the LAST occurrence.
 */
class EventCreatedEvent extends EventBehavior {}

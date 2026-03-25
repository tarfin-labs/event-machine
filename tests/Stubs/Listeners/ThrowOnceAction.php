<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Listeners;

use RuntimeException;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Listener action that throws on first invocation, succeeds on retry.
 * Uses a static counter to track invocations across job retries.
 * Reset $callCount = 0 in beforeEach for test isolation.
 */
class ThrowOnceAction extends ActionBehavior
{
    public static int $callCount = 0;

    public function __invoke(ContextManager $context): void
    {
        self::$callCount++;

        if (self::$callCount === 1) {
            throw new RuntimeException('Intentional first-call failure for testing');
        }

        $context->set('listenerRan', true);
    }
}

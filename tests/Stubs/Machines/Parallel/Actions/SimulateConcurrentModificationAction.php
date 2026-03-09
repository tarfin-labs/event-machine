<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Closure;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Test-only entry action that simulates concurrent DB modification.
 *
 * During execution (lockless phase), it runs a callback that modifies
 * the machine's DB state — simulating another job or send() completing
 * concurrently. This triggers the under-lock double-guard abort path.
 */
class SimulateConcurrentModificationAction extends ActionBehavior
{
    public static ?Closure $onExecute       = null;
    public static bool $shouldModifyContext = true;

    public function __invoke(ContextManager $context): void
    {
        if (static::$shouldModifyContext) {
            $context->set('concurrent_result', 'processed');
        }

        if (static::$onExecute !== null) {
            (static::$onExecute)();
        }
    }
}

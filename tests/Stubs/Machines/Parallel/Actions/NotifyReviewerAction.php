<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class NotifyReviewerAction extends ActionBehavior
{
    private static bool $executed = false;

    public function __invoke(ContextManager $context): void
    {
        self::$executed = true;
        $context->set('reviewer_notified', true);
    }

    public static function wasExecuted(): bool
    {
        return self::$executed;
    }

    public static function reset(): void
    {
        self::$executed = false;
    }
}

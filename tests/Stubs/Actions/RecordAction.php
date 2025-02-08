<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RecordAction extends ActionBehavior
{
    private static bool $executed = false;

    public function __invoke(): void
    {
        self::$executed = true;
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

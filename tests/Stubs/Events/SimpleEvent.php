<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Testing\HasBuilder;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Builders\SimpleEventBuilder;

/**
 * @use HasBuilder<SimpleEventBuilder>
 */
class SimpleEvent extends EventBehavior
{
    use HasBuilder;

    public static function getType(): string
    {
        return 'SIMPLE_EVENT';
    }

    protected static function resolveBuilderClass(): string
    {
        return SimpleEventBuilder::class;
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Behaviors;

use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

class TestBehaviorWithNestedRequiredContext extends InvokableBehavior
{
    /** @var array<string, string> */
    public static array $requiredContext = [
        'deeply.nested.value'   => 'string',
        'another.nested.number' => 'int',
    ];

    public function __invoke(): void {}
}

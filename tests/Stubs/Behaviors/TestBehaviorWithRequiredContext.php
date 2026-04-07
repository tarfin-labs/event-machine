<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Behaviors;

use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

class TestBehaviorWithRequiredContext extends InvokableBehavior
{
    /** @var array<string, string> */
    public static array $requiredContext = [
        'user.id'          => 'int',
        'user.name'        => 'string',
        'settings.enabled' => 'bool',
    ];

    public function __invoke(): void {}
}

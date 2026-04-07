<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Behaviors;

use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

class TestBehaviorWithoutRequiredContext extends InvokableBehavior
{
    public function __invoke(): void {}
}

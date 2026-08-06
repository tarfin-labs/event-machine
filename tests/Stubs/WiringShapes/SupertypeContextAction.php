<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SupertypeContextAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void {}
}

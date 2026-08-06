<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class UntypedParamAction extends ActionBehavior
{
    public function __invoke($anything): void {}
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class UnionFirstNotContextAction extends ActionBehavior
{
    public function __invoke(State|ShapeOtherContext $state): void {}
}

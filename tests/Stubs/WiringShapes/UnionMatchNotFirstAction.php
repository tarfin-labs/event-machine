<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class UnionMatchNotFirstAction extends ActionBehavior
{
    public function __invoke(ShapeOtherContext|ShapeContext $context): void {}
}

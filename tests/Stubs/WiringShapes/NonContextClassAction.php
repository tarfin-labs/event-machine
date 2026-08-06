<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class NonContextClassAction extends ActionBehavior
{
    public function __invoke(EventCollection $history): void {}
}

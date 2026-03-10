<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class TestThrowingAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        throw new \RuntimeException('Action blew up');
    }
}

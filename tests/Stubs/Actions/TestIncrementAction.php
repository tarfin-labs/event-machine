<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class TestIncrementAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('count', ($context->get('count') ?? 0) + 1);
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SetLevelAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, string $level): void
    {
        $context->set('level', $level);
    }
}

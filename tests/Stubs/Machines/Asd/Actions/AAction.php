<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

class AAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior, ?array $arguments = null): void
    {
        ModelA::create([
            'value' => 'lorem ipsum dolor',
        ]);
    }
}

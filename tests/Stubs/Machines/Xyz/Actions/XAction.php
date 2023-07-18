<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class XAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null
    ): void {
        $context->value .= 'x';

        $this->raise(['type' => '@x']);
    }
}

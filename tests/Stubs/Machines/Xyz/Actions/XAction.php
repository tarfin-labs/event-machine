<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class XAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->value .= 'x';

        $this->raise(['type' => '@x']);
    }
}

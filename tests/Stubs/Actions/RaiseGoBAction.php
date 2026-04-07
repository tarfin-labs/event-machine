<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RaiseGoBAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->raise(['type' => 'GO_B']);
    }
}

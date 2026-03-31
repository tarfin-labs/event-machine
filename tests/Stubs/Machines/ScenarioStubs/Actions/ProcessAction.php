<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ProcessAction extends ActionBehavior
{
    public function __invoke(ContextManager $ctx): void
    {
        $ctx->set('processed', true);
    }
}

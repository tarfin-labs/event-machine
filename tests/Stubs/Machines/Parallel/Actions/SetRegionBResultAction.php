<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SetRegionBResultAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('regionBResult', 'processed_by_b');
    }
}

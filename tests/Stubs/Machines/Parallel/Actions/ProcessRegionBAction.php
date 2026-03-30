<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ProcessRegionBAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('regionBData', 'processed_by_b');
        $this->raise(['type' => 'REGION_B_PROCESSED']);
    }
}

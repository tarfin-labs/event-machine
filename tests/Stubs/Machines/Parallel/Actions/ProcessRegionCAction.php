<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ProcessRegionCAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('regionCResult', 'processed_by_c');
        $this->raise(['type' => 'REGION_C_PROCESSED']);
    }
}

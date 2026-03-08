<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ProcessRegionDAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('region_d_result', 'processed_by_d');
        $this->raise(['type' => 'REGION_D_PROCESSED']);
    }
}

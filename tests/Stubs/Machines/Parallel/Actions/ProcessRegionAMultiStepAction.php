<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ProcessRegionAMultiStepAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('region_a_result', 'processed_by_a');
        $this->raise(['type' => 'STEP_1_DONE']);
        $this->raise(['type' => 'STEP_2_DONE']);
    }
}

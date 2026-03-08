<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RegionBDeepContextAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('report', ['turmob' => ['status' => 'clean', 'checked_at' => '2026-03-08']]);
        $context->set('region_b_result', 'processed_by_b');
    }
}

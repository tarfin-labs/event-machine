<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that sets deep nested context AND raises event for auto-completion.
 * Simulates a Turmob API response being stored in nested report context.
 */
class ProcessRegionBDeepReportAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('report', ['turmob' => ['status' => 'clean', 'checked_at' => '2026-03-08']]);
        $context->set('regionBData', 'processed_by_b');
        $this->raise(['type' => 'REGION_B_PROCESSED']);
    }
}

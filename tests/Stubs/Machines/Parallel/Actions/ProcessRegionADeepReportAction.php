<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that sets deep nested context AND raises event for auto-completion.
 * Simulates a Findeks API response being stored in nested report context.
 */
class ProcessRegionADeepReportAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('report', ['findeks' => ['score' => 750, 'provider' => 'kkb']]);
        $context->set('regionAData', 'processed_by_a');
        $this->raise(['type' => 'REGION_A_PROCESSED']);
    }
}

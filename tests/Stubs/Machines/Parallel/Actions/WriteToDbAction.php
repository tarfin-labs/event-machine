<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that writes to DB (simulates a side-effect like creating an order).
 * Used to test transaction safety in ParallelRegionJob's lock-protected section.
 */
class WriteToDbAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        DB::table('test_side_effects')->insert(['value' => 'created_by_action']);
    }
}

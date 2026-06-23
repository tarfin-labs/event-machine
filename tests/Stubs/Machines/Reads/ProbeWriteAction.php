<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads;

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Writes a row to the `atomicity_probe` table during a transition.
 *
 * Used to prove that a persist() failure rolls back the action's committed DB write
 * (the two run in one transaction for transactional events).
 */
class ProbeWriteAction extends ActionBehavior
{
    public function __invoke(): void
    {
        DB::table('atomicity_probe')->insert(['note' => 'written']);
    }
}

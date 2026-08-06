<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Referenced from exactly one route, so a dropped route surfaces as a missing class.
 */
class ExitAction extends ActionBehavior
{
    public function __invoke(): void {}
}

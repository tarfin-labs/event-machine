<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage;

use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class CoverageCalculator extends CalculatorBehavior
{
    public function __invoke(): void {}
}

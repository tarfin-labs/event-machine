<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Calculators;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class DoubleCountCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('count', $context->get('count') * 2);
    }
}

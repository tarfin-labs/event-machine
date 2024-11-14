<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Calculators;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class AverageCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $values = $context->get('values');
        $total  = $context->get('total');
        $count  = count($values);

        $context->set('average', $total / $count);
    }
}

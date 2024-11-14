<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Calculators;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class TotalCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $values = $context->get('values');

        $context->set('total', array_sum($values));
    }
}

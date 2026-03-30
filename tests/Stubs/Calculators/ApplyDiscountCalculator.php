<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Calculators;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class ApplyDiscountCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context, float $rate): void
    {
        $total = $context->get('total');

        $context->set('total', $total - ($total * $rate));
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Calculators;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class PriceCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $quantity  = $context->get('quantity');
        $unitPrice = $context->get('unitPrice');
        $context->set('totalPrice', $quantity * $unitPrice);
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ApplyDiscountAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $totalPrice = $context->get('totalPrice');
        $finalPrice = (int) ($totalPrice * 0.9); // 10% discount

        $context->set('finalPrice', $finalPrice);
    }
}

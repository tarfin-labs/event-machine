<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class StoreStatusCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $context->set('dealer_code', $event->payload['dealer_code'] ?? null);
        $context->set('plate_number', $event->payload['plate_number'] ?? null);
    }
}

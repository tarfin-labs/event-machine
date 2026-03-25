<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

class StorePingPayloadCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $context->set('ping_payload', $event->payload);
    }
}

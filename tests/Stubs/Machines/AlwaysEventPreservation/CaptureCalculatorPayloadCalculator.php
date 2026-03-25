<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

/**
 * Calculator that captures event payload into context.
 */
class CaptureCalculatorPayloadCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $context->set('calculatorPayload', $event->payload);
    }
}

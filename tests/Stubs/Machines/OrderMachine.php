<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [
                    'itemsCount' => 0,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'CREATE_ORDER' => [
                                'target'      => 'processing',
                                'calculators' => 'calculateOrderTotalCalculator',
                                'guards'      => 'validateOrderGuard',
                                'actions'     => 'createOrderAction',
                            ],
                        ],
                    ],
                    'processing' => [],
                ],
            ],
            behavior: [
                'calculators' => [
                    'calculateOrderTotalCalculator' => function (ContextManager $context): void {
                        $context->itemsCount *= 10;
                    },
                ],
                'guards' => [
                    'validateOrderGuard' => function (ContextManager $context): bool {
                        return $context->get('itemsCount') > 0;
                    },
                ],
                'actions' => [
                    'createOrderAction' => function (ContextManager $context): void {
                        $context->set('orderCreated', true);
                    },
                ],
                'events' => [
                    'orderCreatedEvent' => new class() extends EventBehavior {
                        public static function getType(): string
                        {
                            return 'ORDER_CREATED';
                        }
                    },
                ],
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [
                    'items_count' => 0,
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
                'context'     => GenericContext::class,
                'calculators' => [
                    'calculateOrderTotalCalculator' => function (ContextManager $context): void {
                        $context->items_count *= 10;
                    },
                ],
                'guards' => [
                    'validateOrderGuard' => function (ContextManager $context): bool {
                        return $context->get('items_count') > 0;
                    },
                ],
                'actions' => [
                    'createOrderAction' => function (ContextManager $context): void {
                        $context->set('order_created', true);
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

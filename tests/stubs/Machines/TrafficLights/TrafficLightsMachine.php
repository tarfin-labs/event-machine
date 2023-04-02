<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\ContextDefinition;
use Tarfinlabs\EventMachine\EventMachine;
use Tarfinlabs\EventMachine\MachineDefinition;

class TrafficLightsMachine extends EventMachine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'active',
                'context' => [
                    'count' => 1,
                ],
                'states' => [
                    'active' => [
                        'on' => [
                            'MUT' => [
                                'guards'  => 'isEvenGuard',
                                'actions' => 'multiplyByTwoAction',
                            ],
                            'INC' => ['actions' => 'incrementAction'],
                            'DEC' => ['actions' => 'decrementAction'],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'multiplyByTwoAction' => function (ContextDefinition $context): void {
                        $context->set('count', $context->get('count') * 2);
                    },
                    'incrementAction' => function (ContextDefinition $context, array $event): void {
                        $context->set('count', $context->get('count') + 1);
                    },
                    'decrementAction' => function (ContextDefinition $context, array $event): void {
                        $context->set('count', $context->get('count') - 1);
                    },
                ],
                'guards' => [
                    'isEvenGuard' => function (ContextDefinition $context, array $event): bool {
                        return $context->get('count') % 2 === 0;
                    },
                ],
            ],
        );;
    }
}

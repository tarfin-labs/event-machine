<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\EventMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DecrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\MultiplyByTwoAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsEvenGuard;

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
                    'multiplyByTwoAction' => MultiplyByTwoAction::class,
                    'incrementAction'     => IncrementAction::class,
                    'decrementAction'     => DecrementAction::class,
                ],
                'guards' => [
                    'isEvenGuard' => IsEvenGuard::class,
                ],
            ],
        );
    }
}

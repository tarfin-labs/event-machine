<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\EventMachine;
use Tarfinlabs\EventMachine\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsEvenGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DecrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\MultiplyByTwoAction;

class TrafficLightsMachineInlineBehavior extends EventMachine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'active',
                // TODO: Convert this to ContextBehavior class
                'context' => [
                    'count' => 1,
                ],
                'states' => [
                    'active' => [
                        'on' => [
                            'MUT' => [
                                'guards'  => IsEvenGuard::class,
                                'actions' => MultiplyByTwoAction::class,
                            ],
                            'INC' => ['actions' => IncrementAction::class],
                            'DEC' => ['actions' => DecrementAction::class],
                        ],
                    ],
                ],
            ],
        );
    }
}

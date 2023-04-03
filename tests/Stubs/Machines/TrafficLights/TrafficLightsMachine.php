<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\EventMachine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsEvenGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\DecreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\MultiplyEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DecrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\MultiplyByTwoAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DoNothingInsideClassAction;

class TrafficLightsMachine extends EventMachine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'active',
                'context' => TrafficLightsContext::class,
                'states'  => [
                    'active' => [
                        'on' => [
                            'MUT' => [
                                'guards'  => IsEvenGuard::class,
                                'actions' => [
                                    MultiplyByTwoAction::class,
                                    'doNothingAction',
                                    'doNothingInsideClassAction',
                                ],
                            ],
                            IncreaseEvent::class => [
                                'actions' => IncrementAction::class,
                            ],
                            'DEC' => [
                                'actions' => DecrementAction::class,
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'events' => [
                    'MUT' => MultiplyEvent::class,
                    // TODO: I should be able to rename the event here
                    // TODO: So if not defined use class's getType method
                    'DEC' => DecreaseEvent::class,
                ],
                'actions' => [
                    'doNothingAction'            => function (): void {},
                    'doNothingInsideClassAction' => DoNothingInsideClassAction::class,
                ],
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\Definition\EventMachine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsEvenGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\DecreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\MultiplyEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\AddValueAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DecrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\MultiplyByTwoAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DoNothingInsideClassAction;

class TrafficLightsMachine extends EventMachine
{
    public static function build(): MachineDefinition
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
                            'DEX' => [
                                'actions' => DecrementAction::class,
                            ],
                            AddValueEvent::class => [
                                'actions' => AddValueAction::class,
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'events' => [
                    'MUT' => MultiplyEvent::class,
                    'DEX' => DecreaseEvent::class,
                ],
                'actions' => [
                    'doNothingAction'            => function (): void {},
                    'doNothingInsideClassAction' => DoNothingInsideClassAction::class,
                ],
            ],
        );
    }
}
<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsEvenGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\DecreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\MultiplyEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\AddValueAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DecrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\SubtactValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\MultiplyByTwoAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\SubtractValueAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\AddAnotherValueAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\DoNothingInsideClassAction;

class TrafficLightsMachine extends Machine
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
                                    'doNothingAction2',
                                    'doNothingInsideClassAction',
                                ],
                            ],
                            IncreaseEvent::class        => ['actions' => IncrementAction::class],
                            'DEX'                       => ['actions' => DecrementAction::class],
                            AddValueEvent::class        => ['actions' => AddValueAction::class],
                            AddAnotherValueEvent::class => ['actions' => AddAnotherValueAction::class],
                            SubtactValueEvent::class    => ['actions' => SubtractValueAction::class],
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
                    'doNothingAction2'           => function (ContextManager $context, EventBehavior $eventBehavior): void {},
                    'doNothingInsideClassAction' => DoNothingInsideClassAction::class,
                ],
            ],
        );
    }
}

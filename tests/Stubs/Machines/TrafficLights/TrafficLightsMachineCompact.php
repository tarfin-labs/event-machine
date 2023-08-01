<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\Actor\Machine;
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

class TrafficLightsMachineCompact extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'active',
                'states'  => [
                    'active' => [
                        'on' => [
                            MultiplyEvent::class => [
                                'guards'  => IsEvenGuard::class,
                                'actions' => MultiplyByTwoAction::class,
                            ],
                            IncreaseEvent::class => ['actions' => IncrementAction::class],
                            DecreaseEvent::class => ['actions' => DecrementAction::class],
                            AddValueEvent::class => ['actions' => AddValueAction::class],
                        ],
                    ],
                ],
            ],
        );
    }
}

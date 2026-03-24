<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

class TestabilityMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [
                    'count' => 0,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'INCREMENT' => [
                                'actions' => IncrementWithServiceAction::class,
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}

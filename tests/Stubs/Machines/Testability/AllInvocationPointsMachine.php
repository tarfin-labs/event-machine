<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\LogExitAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\LogEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Guards\IsCountPositiveGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Calculators\DoubleCountCalculator;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

class AllInvocationPointsMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [
                    'count'   => 1,
                    'entered' => false,
                    'exited'  => false,
                ],
                'states' => [
                    'idle' => [
                        'exit' => LogExitAction::class,
                        'on'   => [
                            'PROCESS' => [
                                'target'      => 'active',
                                'guards'      => IsCountPositiveGuard::class,
                                'calculators' => DoubleCountCalculator::class,
                                'actions'     => IncrementWithServiceAction::class,
                            ],
                        ],
                    ],
                    'active' => [
                        'entry' => LogEntryAction::class,
                    ],
                ],
            ],
        );
    }
}

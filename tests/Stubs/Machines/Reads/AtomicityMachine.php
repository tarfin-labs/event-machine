<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class AtomicityMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'atomicity_machine',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [],
                'states'         => [
                    'idle' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'done',
                                'actions' => ProbeWriteAction::class,
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'GO' => GoEvent::class,
                ],
                'actions' => [
                    ProbeWriteAction::class,
                ],
            ],
        );
    }
}

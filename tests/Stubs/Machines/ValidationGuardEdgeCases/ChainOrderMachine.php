<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailValidationGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\SecondAlwaysFailValidationGuard;

class ChainOrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'chain_order',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => [
                                'target' => 'done',
                                'guards' => [
                                    AlwaysFailValidationGuard::class,
                                    SecondAlwaysFailValidationGuard::class,
                                ],
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    AlwaysFailValidationGuard::class,
                    SecondAlwaysFailValidationGuard::class,
                ],
            ],
        );
    }
}

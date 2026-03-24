<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysPassValidationGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\SecondAlwaysFailValidationGuard;

class ChainSecondFailsMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'chain_second_fails',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => [
                                'target' => 'done',
                                'guards' => [
                                    AlwaysPassValidationGuard::class,
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
                    AlwaysPassValidationGuard::class,
                    SecondAlwaysFailValidationGuard::class,
                ],
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailValidationGuard;

class NoFallthroughMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'no_fallthrough',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => [
                                [
                                    'target' => 'rejected',
                                    'guards' => AlwaysFailValidationGuard::class,
                                ],
                                [
                                    'target' => 'accepted',
                                ],
                            ],
                        ],
                    ],
                    'rejected' => ['type' => 'final'],
                    'accepted' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    AlwaysFailValidationGuard::class,
                ],
            ],
        );
    }
}

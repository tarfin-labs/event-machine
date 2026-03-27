<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysPassRegularGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailValidationGuard;

class CombinedGuardsMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'combined_guards',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => [
                                'target' => 'done',
                                'guards' => [
                                    AlwaysPassRegularGuard::class,
                                    AlwaysFailValidationGuard::class,
                                ],
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    AlwaysPassRegularGuard::class,
                    AlwaysFailValidationGuard::class,
                ],
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAllowedGuard;

/**
 * Machine with @always transition guarded by IsAllowedGuard.
 * Used to test guards: parameter timing — guard must be faked BEFORE init.
 */
class AlwaysGuardMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'always_guard',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => [
                                'target' => 'done',
                                'guards' => 'isAllowedGuard',
                            ],
                            'GO' => [
                                'target' => 'done',
                                'guards' => 'isAllowedGuard',
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'isAllowedGuard' => IsAllowedGuard::class,
                ],
            ],
        );
    }
}

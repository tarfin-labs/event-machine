<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\LogAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAllowedGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Machine with @always transition guarded by IsAllowedGuard + LogAction.
 * Used to test guards:/faking: parameter timing — must be faked BEFORE init.
 */
class AlwaysGuardMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'always_guard',
                'initial' => 'idle',
                'context' => ['logged' => false],
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'done',
                                'guards'  => 'isAllowedGuard',
                                'actions' => 'logAction',
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
                'context' => GenericContext::class,
                'guards'  => [
                    'isAllowedGuard' => IsAllowedGuard::class,
                ],
                'actions' => [
                    'logAction' => LogAction::class,
                ],
            ],
        );
    }
}

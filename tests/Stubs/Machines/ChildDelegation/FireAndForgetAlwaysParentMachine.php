<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Fire-and-forget parent: @always transition pattern.
 *
 * Dispatches ImmediateChildMachine to queue without @done.
 * Uses @always to transition to 'prevented' immediately after dispatch.
 */
class FireAndForgetAlwaysParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'ff_always_parent',
                'initial' => 'idle',
                'context' => [
                    'tckn' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['REJECT' => 'dispatching_verification'],
                    ],
                    'dispatching_verification' => [
                        'machine' => ImmediateChildMachine::class,
                        'with'    => ['tckn'],
                        'queue'   => 'child-queue',
                        // No @done → fire-and-forget
                        'on' => ['@always' => 'prevented'],
                    ],
                    'prevented' => [
                        'on' => ['RETRY' => 'idle'],
                    ],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}

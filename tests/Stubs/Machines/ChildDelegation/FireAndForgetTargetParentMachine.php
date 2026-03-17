<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Fire-and-forget parent: target transition pattern.
 *
 * Dispatches SimpleChildMachine to named queue with connection/retry.
 * Uses target key to transition to 'prevented' immediately after dispatch.
 */
class FireAndForgetTargetParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'ff_target_parent',
                'initial' => 'idle',
                'context' => [
                    'tckn' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['REJECT' => 'dispatching_verification'],
                    ],
                    'dispatching_verification' => [
                        'machine'    => SimpleChildMachine::class,
                        'with'       => ['tckn'],
                        'queue'      => 'verifications',
                        'connection' => 'redis',
                        'retry'      => 3,
                        'target'     => 'prevented',
                    ],
                    'prevented' => [
                        'on' => ['RETRY' => 'idle'],
                    ],
                ],
            ],
        );
    }
}

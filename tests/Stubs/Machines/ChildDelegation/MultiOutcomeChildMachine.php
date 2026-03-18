<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine with 3 reachable final states for testing @done.{state} routing.
 *
 * Does NOT auto-complete — stays in 'processing' until it receives
 * APPROVE, REJECT, or EXPIRE events.
 */
class MultiOutcomeChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'multi_outcome_child',
            'initial' => 'processing',
            'context' => [
                'decision' => null,
                'reason'   => null,
            ],
            'states' => [
                'processing' => [
                    'on' => [
                        'APPROVE' => 'approved',
                        'REJECT'  => 'rejected',
                        'EXPIRE'  => 'expired',
                    ],
                ],
                'approved' => [
                    'type'   => 'final',
                    'output' => ['decision'],
                ],
                'rejected' => [
                    'type'   => 'final',
                    'output' => ['reason'],
                ],
                'expired' => [
                    'type' => 'final',
                ],
            ],
        ]);
    }
}

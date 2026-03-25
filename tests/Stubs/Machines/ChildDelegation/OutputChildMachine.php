<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine with output key on final state.
 * Only exposes payment_id and status to parent, not internal_retry_count.
 */
class OutputChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'output_child',
                'initial' => 'done',
                'context' => [
                    'paymentId'          => 'pay_xyz',
                    'status'             => 'approved',
                    'internalRetryCount' => 3,
                ],
                'states' => [
                    'done' => [
                        'type'   => 'final',
                        'output' => ['paymentId', 'status'],
                    ],
                ],
            ],
        );
    }
}

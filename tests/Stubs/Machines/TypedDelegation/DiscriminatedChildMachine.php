<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\ApprovalOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\RejectionOutput;

class DiscriminatedChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'discriminated_child',
                'initial' => 'reviewing',
                'context' => [
                    'approvalId' => null,
                    'approvedBy' => null,
                    'reason'     => null,
                    'reviewerId' => null,
                ],
                'states' => [
                    'reviewing' => [
                        'on' => [
                            'APPROVE' => 'approved',
                            'REJECT'  => 'rejected',
                        ],
                    ],
                    'approved' => [
                        'type'   => 'final',
                        'output' => ApprovalOutput::class,
                    ],
                    'rejected' => [
                        'type'   => 'final',
                        'output' => RejectionOutput::class,
                    ],
                ],
            ],
        );
    }
}

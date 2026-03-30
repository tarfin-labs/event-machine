<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class DiscriminatedParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'discriminated_parent',
                'initial' => 'idle',
                'context' => [
                    'approvalId'      => null,
                    'rejectionReason' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'reviewing'],
                    ],
                    'reviewing' => [
                        'machine'        => DiscriminatedChildMachine::class,
                        '@done.approved' => ['target' => 'completed'],
                        '@done.rejected' => ['target' => 'under_review'],
                        '@fail'          => ['target' => 'errored'],
                    ],
                    'completed' => [
                        'type' => 'final',
                    ],
                    'under_review' => [
                        'type' => 'final',
                    ],
                    'errored' => [
                        'type' => 'final',
                    ],
                ],
            ],
        );
    }
}

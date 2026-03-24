<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

class ImmediateApprovedChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'immediate_approved',
            'initial' => 'approved',
            'context' => ['decision' => 'yes'],
            'states'  => [
                'approved' => ['type' => 'final', 'output' => ['decision']],
            ],
        ],
            behavior: [
                'context' => GenericContext::class,
            ]);
    }
}

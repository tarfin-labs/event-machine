<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

class ImmediateRejectedChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'immediate_rejected',
            'initial' => 'rejected',
            'context' => ['reason' => 'insufficient_funds'],
            'states'  => [
                'rejected' => ['type' => 'final', 'output' => ['reason']],
            ],
        ],
            behavior: [
                'context' => GenericContext::class,
            ]);
    }
}

<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine without input/failure declarations — tests backward compatibility.
 */
class OptionalContractChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'optional_contract_child',
                'initial' => 'processing',
                'context' => [
                    'status' => 'pending',
                ],
                'states' => [
                    'processing' => [
                        'on' => ['DONE' => 'completed'],
                    ],
                    'completed' => [
                        'type' => 'final',
                    ],
                ],
            ],
        );
    }
}

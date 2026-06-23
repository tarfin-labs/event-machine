<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Minimal machine for exercising read-only projections ("reads").
 *
 * Untyped string events keep the stub small — reads never go through event
 * resolution anyway. Context is seeded so reads have something to project.
 */
class ReadsMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'reads_machine',
                'initial'        => 'pending',
                'should_persist' => true,
                'context'        => [
                    'orderId' => 'ORD-1',
                    'total'   => 100,
                ],
                'states' => [
                    'pending' => [
                        'on' => ['SUBMIT' => 'processing'],
                    ],
                    'processing' => [
                        'on' => ['COMPLETE' => 'completed'],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => ['orderId', 'total'],
                    ],
                ],
            ],
            behavior: [
                'outputs' => [
                    'readSummaryOutput' => ReadSummaryOutput::class,
                ],
            ],
        );
    }
}

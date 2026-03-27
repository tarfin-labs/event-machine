<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\StubContractAction;

/**
 * Machine with StubContractAction for testing ScenarioStubContract.
 *
 * idle → SCORE (StubContractAction runs) → scored (final)
 */
class StubContractMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'stub_contract',
                'initial' => 'idle',
                'context' => [
                    'score' => 0,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'SCORE' => [
                                'target'  => 'scored',
                                'actions' => StubContractAction::class,
                            ],
                        ],
                    ],
                    'scored' => ['type' => 'final'],
                ],
            ],
        );
    }
}

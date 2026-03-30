<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAboveThresholdGuard;

class NamedParamsAlwaysMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'named_params_always',
                'initial' => 'idle',
                'context' => [
                    'amount' => 0,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'START' => 'evaluating',
                        ],
                    ],
                    'evaluating' => [
                        'on' => [
                            '@always' => [
                                [
                                    'guards' => [[IsAboveThresholdGuard::class, 'threshold' => 100]],
                                    'target' => 'high',
                                ],
                                [
                                    'guards' => [[IsAboveThresholdGuard::class, 'threshold' => 50]],
                                    'target' => 'medium',
                                ],
                                [
                                    'target' => 'low',
                                ],
                            ],
                        ],
                    ],
                    'high'   => ['type' => 'final'],
                    'medium' => ['type' => 'final'],
                    'low'    => ['type' => 'final'],
                ],
            ],
        );
    }
}

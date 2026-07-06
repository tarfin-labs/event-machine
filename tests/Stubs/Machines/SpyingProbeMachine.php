<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\ProbeOneAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\ProbeTwoAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\BootProbeAction;

/**
 * Stub machine for spying()/testIsolated() tests.
 *
 * BootProbeAction runs as an entry action on the initial state (boot time);
 * ProbeOneAction/ProbeTwoAction plus an inline action run on the GO transition.
 */
class SpyingProbeMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'spying_probe',
                'initial' => 'ready',
                'context' => [],
                'states'  => [
                    'ready' => [
                        'entry' => BootProbeAction::class,
                        'on'    => [
                            'GO' => [
                                'target'  => 'finished',
                                'actions' => [
                                    ProbeOneAction::class,
                                    ProbeTwoAction::class,
                                    'inlineProbeAction',
                                ],
                            ],
                        ],
                    ],
                    'finished' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'inlineProbeAction' => function (): void {
                        // no-op inline action for mixed assertBehaviorRan([]) tests
                    },
                ],
            ],
        );
    }
}

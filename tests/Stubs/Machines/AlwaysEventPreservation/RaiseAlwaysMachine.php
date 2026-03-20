<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine that raises an event which triggers an @always chain.
 *
 * Flow: idle → (START) → raising[entry: raise PROCEED] → (PROCEED) → routing(@always) → done
 */
class RaiseAlwaysMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'raise_always',
                'initial' => 'idle',
                'context' => [
                    'raised_event_type' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'START' => 'raising',
                        ],
                    ],
                    'raising' => [
                        'entry' => 'raiseEventAction',
                        'on'    => [
                            'PROCEED' => 'routing',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'done',
                                'actions' => 'captureRaisedEventAction',
                            ],
                        ],
                    ],
                    'done' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'raiseEventAction'         => RaiseEventAction::class,
                    'captureRaisedEventAction' => CaptureRaisedEventAction::class,
                ],
            ],
        );
    }
}

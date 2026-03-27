<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine where a timer-like event triggers an @always chain.
 *
 * Flow: waiting → (TIMEOUT) → routing(@always) → done
 *
 * Timer events are dispatched as normal send() calls by the timer sweep.
 * The @always action should receive the TIMEOUT event, not @always.
 */
class TimerAfterAlwaysMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'timer_after_always',
                'initial' => 'waiting',
                'context' => [
                    'timerEventType'    => null,
                    'timerEventPayload' => null,
                ],
                'states' => [
                    'waiting' => [
                        'on' => [
                            'TIMEOUT' => 'routing',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'done',
                                'actions' => 'captureTimerEventAction',
                            ],
                        ],
                    ],
                    'done' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureTimerEventAction' => CaptureTimerEventAction::class,
                ],
            ],
        );
    }
}

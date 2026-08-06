<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * References a behavior through every route a behavior can enter a definition by.
 *
 * Each behavior class is used from exactly one route, so a route dropped from the
 * collection walk shows up as a missing class in the set-equality assertion rather
 * than being masked by another route that happens to reach the same class.
 *
 * Deliberately also references things that must NOT be collected: an inline closure,
 * an inline key resolving to a closure, and a child machine class.
 */
class BehaviorCoverageMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'behavior_coverage',
                'initial' => 'idle',
                'context' => [],
                'listen'  => [
                    'entry' => ListenAction::class,
                ],
                'states' => [
                    'idle' => [
                        'entry' => EntryAction::class,
                        'exit'  => ExitAction::class,
                        'on'    => [
                            'TIMED_OUT' => [
                                'target'  => 'working',
                                'after'   => Timer::seconds(30),
                                'actions' => TimerAction::class,
                            ],
                            CoverageStartedEvent::class => [
                                'target'      => 'working',
                                'actions'     => [TransitionAction::class, 'inlineAction'],
                                'guards'      => CoverageGuard::class,
                                'calculators' => CoverageCalculator::class,
                            ],
                        ],
                    ],
                    'working' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'done',
                                'actions' => [[TupleAction::class, 'attempts' => 2]],
                            ],
                        ],
                    ],
                    'done' => [
                        'type'   => 'final',
                        'entry'  => AlwaysAction::class,
                        'output' => 'coverageOutput',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'mapAction'    => MapAction::class,
                    'inlineAction' => function (): void {},
                ],
                'outputs' => [
                    'coverageOutput' => CoverageOutput::class,
                ],
                'events' => [
                    'COVERAGE_STARTED' => CoverageStartedEvent::class,
                ],
            ],
        );
    }
}

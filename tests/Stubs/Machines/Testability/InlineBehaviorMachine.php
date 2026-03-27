<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

/**
 * Test machine with inline behavior closures for testing InlineBehaviorFake.
 */
class InlineBehaviorMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [
                    'count'      => 0,
                    'processed'  => false,
                    'guardRan'   => false,
                    'calculated' => false,
                    'entryRan'   => false,
                    'exitRan'    => false,
                ],
                'states' => [
                    'idle' => [
                        'exit' => 'exitAction',
                        'on'   => [
                            'PROCESS' => [
                                'target'      => 'active',
                                'guards'      => 'isAllowedGuard',
                                'calculators' => 'doubleCountCalculator',
                                'actions'     => 'processAction',
                            ],
                            'GUARDED' => [
                                'target' => 'active',
                                'guards' => 'blockingGuard',
                            ],
                            'CLASS_ACTION' => [
                                'target'  => 'active',
                                'actions' => IncrementWithServiceAction::class,
                            ],
                        ],
                    ],
                    'active' => [
                        'entry' => 'entryAction',
                        'on'    => [
                            'FINISH' => [
                                'target' => 'done',
                            ],
                        ],
                    ],
                    'done' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'processAction' => fn (ContextManager $context) => $context->set('processed', true),
                    'entryAction'   => fn (ContextManager $context) => $context->set('entryRan', true),
                    'exitAction'    => fn (ContextManager $context) => $context->set('exitRan', true),
                ],
                'guards' => [
                    'isAllowedGuard' => fn (State $state): bool => $state->context->get('count') >= 0,
                    'blockingGuard'  => fn (): bool => false,
                ],
                'calculators' => [
                    'doubleCountCalculator' => fn (ContextManager $context) => $context->set('count', $context->get('count') * 2),
                ],
            ],
        );
    }
}

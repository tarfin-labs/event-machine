<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessInternalGoAction;

test('parallel state entry action raises event → processed in Phase 1', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parent_raise',
            'initial' => 'parallel_parent',
            'context' => [
                'raise_action_ran' => false,
                'setup_complete'   => false,
            ],
            'states' => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'entry'  => ProcessInternalGoAction::class,
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle_a',
                            'states'  => [
                                'idle_a' => [
                                    'on' => [
                                        'INTERNAL_GO' => [
                                            'target'  => 'active_a',
                                            'actions' => 'markSetupAction',
                                        ],
                                    ],
                                ],
                                'active_a' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle_b',
                            'states'  => [
                                'idle_b' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'markSetupAction' => function (ContextManager $context): void {
                    $context->set('setup_complete', true);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Parallel state entry raised INTERNAL_GO → region A transitioned
    expect($state->context->get('raise_action_ran'))->toBeTrue();
    expect($state->context->get('setup_complete'))->toBeTrue();
    expect($state->value)->toContain('parent_raise.parallel_parent.region_a.active_a');
});

test('parallel state entry raises event with no handler → throws NoTransitionDefinitionFoundException', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'no_handler',
            'initial' => 'parallel_parent',
            'context' => [
                'raise_action_ran' => false,
            ],
            'states' => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'entry'  => ProcessInternalGoAction::class,
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle_a',
                            'states'  => [
                                'idle_a' => [],
                                // No handler for INTERNAL_GO
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle_b',
                            'states'  => [
                                'idle_b' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    );

    // Raised event with no handler throws exception (event-machine strict behavior)
    expect(fn () => $definition->getInitialState())->toThrow(
        NoTransitionDefinitionFoundException::class
    );
});

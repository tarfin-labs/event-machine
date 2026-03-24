<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

// ---------------------------------------------------------------------------
// Targetless @done on compound state — actions should run, state stays
// ---------------------------------------------------------------------------

it('runs branch actions on targetless compound @done', function (): void {
    $tracker = new class() {
        public bool $fired = false;
    };

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test_targetless_compound',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'initial' => 'step_one',
                    '@done'   => [
                        'actions' => 'logCompletionAction',
                        // no target — should run actions but stay at final child
                    ],
                    'states' => [
                        'step_one' => [
                            'on' => ['NEXT' => 'completed'],
                        ],
                        'completed' => ['type' => 'final'],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'logCompletionAction' => function () use ($tracker): void {
                    $tracker->fired = true;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'NEXT'], $state);

    // Action should have fired even without a target
    expect($tracker->fired)->toBeTrue();
    // State stays at the final child (no target to transition to)
    expect($state->value)->toBe(['test_targetless_compound.processing.completed']);
});

// ---------------------------------------------------------------------------
// Targetless @done on parallel state — actions should run, state stays
// ---------------------------------------------------------------------------

it('runs branch actions on targetless parallel @done', function (): void {
    $tracker = new class() {
        public bool $fired = false;
    };

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test_targetless_parallel',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'  => 'parallel',
                    '@done' => [
                        'actions' => 'logParallelDoneAction',
                        // no target — actions only
                    ],
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_A' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_B' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'logParallelDoneAction' => function () use ($tracker): void {
                    $tracker->fired = true;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // Action should have fired even without a target
    expect($tracker->fired)->toBeTrue();
    // State stays in the parallel final states (no target to exit to)
    expect($state->value)->toContain('test_targetless_parallel.processing.region_a.finished');
    expect($state->value)->toContain('test_targetless_parallel.processing.region_b.finished');
});

// ---------------------------------------------------------------------------
// Conditional targetless @done — guard passes, actions run, no transition
// ---------------------------------------------------------------------------

it('runs guarded targetless @done branch actions when guard passes', function (): void {
    $tracker = new class() {
        public bool $fired = false;
    };

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test_guarded_targetless',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'initial' => 'step_one',
                    '@done'   => [
                        ['guards' => 'alwaysTrueGuard', 'actions' => 'notifyAction'],
                        // no target on any branch
                    ],
                    'states' => [
                        'step_one' => [
                            'on' => ['NEXT' => 'completed'],
                        ],
                        'completed' => ['type' => 'final'],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'guards'  => [
                'alwaysTrueGuard' => fn () => true,
            ],
            'actions' => [
                'notifyAction' => function () use ($tracker): void {
                    $tracker->fired = true;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'NEXT'], $state);

    expect($tracker->fired)->toBeTrue();
    expect($state->value)->toBe(['test_guarded_targetless.processing.completed']);
});

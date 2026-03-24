<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

it('createEventBehavior returns EventBehavior from array input', function (): void {
    $definition = ParallelDispatchMachine::definition();
    $state      = $definition->getInitialState();

    $event = $definition->createEventBehavior(
        event: ['type' => 'REGION_A_DONE'],
        state: $state,
    );

    expect($event)->toBeInstanceOf(EventDefinition::class);
    expect($event->type)->toBe('REGION_A_DONE');
});

it('createEventBehavior returns EventBehavior instance passed directly', function (): void {
    $definition = ParallelDispatchMachine::definition();
    $state      = $definition->getInitialState();

    $original = new EventDefinition(type: 'REGION_A_DONE');

    $resolved = $definition->createEventBehavior(
        event: $original,
        state: $state,
    );

    // Not in registry, so returned as-is
    expect($resolved)->toBe($original);
});

it('areAllRegionsFinal returns correct result for various states', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'api_test',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_A' => 'final_a'],
                                ],
                                'final_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_B' => 'final_b'],
                                ],
                                'final_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state          = $definition->getInitialState();
    $parallelParent = $definition->idMap['api_test.parallel_parent'];

    // None final
    expect($definition->areAllRegionsFinal($parallelParent, $state))->toBeFalse();

    // Partially final (only region A)
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    expect($definition->areAllRegionsFinal($parallelParent, $state))->toBeFalse();
    expect($state->isInParallelState())->toBeTrue();

    // All final (both regions)
    $state = $definition->transition(['type' => 'DONE_B'], $state);
    // After DONE_B, onDone fires → machine transitions to completed
    expect($state->currentStateDefinition->id)->toBe('api_test.completed');
});

it('areAllRegionsFinal is read-only and does not modify state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'readonly_test',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state          = $definition->getInitialState();
    $parallelParent = $definition->idMap['readonly_test.parallel_parent'];

    $valueBefore = $state->value;

    // Call areAllRegionsFinal multiple times
    $definition->areAllRegionsFinal($parallelParent, $state);
    $definition->areAllRegionsFinal($parallelParent, $state);

    // State unchanged
    expect($state->value)->toBe($valueBefore);
});

it('processParallelOnDone transitions out of parallel state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'on_done_test',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_A' => 'final_a'],
                                ],
                                'final_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_B' => 'final_b'],
                                ],
                                'final_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();

    // Transition to all-final via events
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // processParallelOnDone was called internally — verify result
    expect($state->currentStateDefinition->id)->toBe('on_done_test.completed');
    expect($state->isInParallelState())->toBeFalse();
});

<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\InvalidOutputDefinitionException;

// ═══════════════════════════════════════════════════════════════
//  InvalidOutputDefinitionException — transient states
// ═══════════════════════════════════════════════════════════════

it('throws InvalidOutputDefinitionException when output is defined on a transient (@always) state', function (): void {
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'transient_output',
            'initial' => 'start',
            'states'  => [
                'start' => [
                    'on' => ['GO' => 'calculating'],
                ],
                'calculating' => [
                    'output' => ['total'],
                    'on'     => ['@always' => ['target' => 'done']],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    ))->toThrow(InvalidOutputDefinitionException::class, 'transient state');
});

it('allows output on a non-transient state (no @always)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'normal_output',
            'initial' => 'start',
            'states'  => [
                'start' => [
                    'output' => ['value'],
                    'on'     => ['GO' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    expect($definition)->toBeInstanceOf(MachineDefinition::class);
});

// ═══════════════════════════════════════════════════════════════
//  InvalidOutputDefinitionException — parallel region states
// ═══════════════════════════════════════════════════════════════

it('throws InvalidOutputDefinitionException when output is defined on a parallel region state', function (): void {
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'parallel_region_output',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'step_1',
                            'output'  => ['value'],
                            'states'  => [
                                'step_1' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ))->toThrow(InvalidOutputDefinitionException::class, 'parallel region');
});

it('throws InvalidOutputDefinitionException when output is defined on a child of a parallel region', function (): void {
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'parallel_child_output',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'step_1',
                            'states'  => [
                                'step_1' => [
                                    'output' => ['value'],
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ))->toThrow(InvalidOutputDefinitionException::class, 'parallel region');
});

it('allows output on the parallel state itself', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_output_ok',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'output' => ['combinedData'],
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    );

    expect($definition)->toBeInstanceOf(MachineDefinition::class);
});

it('exception message includes the state route for debugging', function (): void {
    try {
        MachineDefinition::define(
            config: [
                'id'      => 'route_in_error',
                'initial' => 'start',
                'states'  => [
                    'start' => [
                        'output' => ['total'],
                        'on'     => ['@always' => ['target' => 'done']],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
        );
        $this->fail('Expected exception not thrown');
    } catch (InvalidOutputDefinitionException $e) {
        expect($e->getMessage())->toContain('start');
    }
});

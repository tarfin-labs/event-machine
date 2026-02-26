<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('parallel state type is correctly detected', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'regionA' => [
                        'initial' => 'a1',
                        'states'  => [
                            'a1' => [],
                            'a2' => [],
                        ],
                    ],
                    'regionB' => [
                        'initial' => 'b1',
                        'states'  => [
                            'b1' => [],
                            'b2' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $activeState = $definition->idMap['test.active'];

    expect($activeState->type)->toBe(StateDefinitionType::PARALLEL);
});

test('parallel state enters all regions simultaneously', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'regionA' => [
                        'initial' => 'a1',
                        'states'  => [
                            'a1' => [],
                        ],
                    ],
                    'regionB' => [
                        'initial' => 'b1',
                        'states'  => [
                            'b1' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Both regions should be in their initial states
    expect($state->value)->toHaveCount(2);
    expect($state->value)->toContain('test.active.regionA.a1');
    expect($state->value)->toContain('test.active.regionB.b1');
});

test('state value contains all active region states', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'track' => [
                        'initial' => 'paused',
                        'states'  => [
                            'paused'  => [],
                            'playing' => [],
                        ],
                    ],
                    'volume' => [
                        'initial' => 'unmuted',
                        'states'  => [
                            'unmuted' => [],
                            'muted'   => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    expect($state->value)->toContain('test.active.track.paused');
    expect($state->value)->toContain('test.active.volume.unmuted');
});

test('matches method works with partial state paths', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'track' => [
                        'initial' => 'paused',
                        'states'  => [
                            'paused'  => [],
                            'playing' => [],
                        ],
                    ],
                    'volume' => [
                        'initial' => 'unmuted',
                        'states'  => [
                            'unmuted' => [],
                            'muted'   => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Should match partial paths
    expect($state->matches('active.track.paused'))->toBeTrue();
    expect($state->matches('active.volume.unmuted'))->toBeTrue();

    // Should not match non-active states
    expect($state->matches('active.track.playing'))->toBeFalse();
    expect($state->matches('active.volume.muted'))->toBeFalse();
});

test('matchesAll verifies multiple states simultaneously', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'track' => [
                        'initial' => 'paused',
                        'states'  => [
                            'paused' => [],
                        ],
                    ],
                    'volume' => [
                        'initial' => 'unmuted',
                        'states'  => [
                            'unmuted' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    expect($state->matchesAll([
        'active.track.paused',
        'active.volume.unmuted',
    ]))->toBeTrue();

    expect($state->matchesAll([
        'active.track.playing',
        'active.volume.unmuted',
    ]))->toBeFalse();
});

test('isInParallelState returns true for parallel states', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'regionA' => [
                        'initial' => 'a1',
                        'states'  => [
                            'a1' => [],
                        ],
                    ],
                    'regionB' => [
                        'initial' => 'b1',
                        'states'  => [
                            'b1' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    expect($state->isInParallelState())->toBeTrue();
});

test('parallel state cannot have initial property', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'parallel',
        'states'  => [
            'parallel' => [
                'type'    => 'parallel',
                'initial' => 'regionA', // Invalid - parallel cannot have initial
                'states'  => [
                    'regionA' => [
                        'initial' => 'a1',
                        'states'  => [
                            'a1' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]))->toThrow(\Tarfinlabs\EventMachine\Exceptions\InvalidParallelStateDefinitionException::class);
});

test('parallel state must have at least one region', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'parallel',
        'states'  => [
            'parallel' => [
                'type'   => 'parallel',
                'states' => [], // Invalid - must have at least one region
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class);
});

test('three parallel regions initialize correctly', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'orderWorkflow',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                'states' => [
                    'documents' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending'  => [],
                            'complete' => ['type' => 'final'],
                        ],
                    ],
                    'delivery' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => [],
                            'shipped'   => [],
                            'delivered' => ['type' => 'final'],
                        ],
                    ],
                    'invoice' => [
                        'initial' => 'draft',
                        'states'  => [
                            'draft' => [],
                            'sent'  => [],
                            'paid'  => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    expect($state->value)->toHaveCount(3);
    expect($state->matches('processing.documents.pending'))->toBeTrue();
    expect($state->matches('processing.delivery.preparing'))->toBeTrue();
    expect($state->matches('processing.invoice.draft'))->toBeTrue();
});

<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

uses(RefreshDatabase::class);

test('parallel state can be restored from database', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'parallel_machine',
        'initial' => 'active',
        'context' => ['value' => 0],
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'track' => [
                        'initial' => 'paused',
                        'states'  => [
                            'paused' => [
                                'on' => [
                                    'PLAY' => 'playing',
                                ],
                            ],
                            'playing' => [],
                        ],
                    ],
                    'volume' => [
                        'initial' => 'unmuted',
                        'states'  => [
                            'unmuted' => [
                                'on' => [
                                    'MUTE' => 'muted',
                                ],
                            ],
                            'muted' => [],
                        ],
                    ],
                ],
            ],
        ],
    ],
        behavior: [
            'context' => GenericContext::class,
        ]);

    // Create and transition
    $machine = Machine::create($definition);
    $machine->send(['type' => 'PLAY']);
    $machine->send(['type' => 'MUTE']);

    $rootEventId = $machine->state->history->first()->id;

    expect($machine->state->matches('active.track.playing'))->toBeTrue();
    expect($machine->state->matches('active.volume.muted'))->toBeTrue();

    // Restore from database
    $restoredMachine = Machine::create($definition);
    $restoredState   = $restoredMachine->restoreStateFromRootEventId($rootEventId);

    expect($restoredState->matches('active.track.playing'))->toBeTrue();
    expect($restoredState->matches('active.volume.muted'))->toBeTrue();
});

test('parallel state value is correctly stored in machine events', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'parallel_machine',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'region_a' => [
                        'initial' => 'a1',
                        'states'  => [
                            'a1' => [],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'b1',
                        'states'  => [
                            'b1' => [],
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

    $machine   = Machine::create($definition);
    $lastEvent = $machine->state->history->last();

    // The machine_value should be an array with both region states
    expect($lastEvent->machine_value)->toBeArray();
    expect($lastEvent->machine_value)->toHaveCount(2);
    expect($lastEvent->machine_value)->toContain('parallel_machine.active.region_a.a1');
    expect($lastEvent->machine_value)->toContain('parallel_machine.active.region_b.b1');
});

test('parallel state restoration after multiple transitions', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'workflow',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                'states' => [
                    'documents' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => [
                                'on' => [
                                    'UPLOAD' => 'reviewing',
                                ],
                            ],
                            'reviewing' => [
                                'on' => [
                                    'APPROVE' => 'complete',
                                ],
                            ],
                            'complete' => ['type' => 'final'],
                        ],
                    ],
                    'payment' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => [
                                'on' => [
                                    'PAY' => 'complete',
                                ],
                            ],
                            'complete' => ['type' => 'final'],
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

    // Create and run through multiple transitions
    $machine = Machine::create($definition);
    $machine->send(['type' => 'UPLOAD']);
    $machine->send(['type' => 'PAY']);
    $machine->send(['type' => 'APPROVE']);

    $rootEventId = $machine->state->history->first()->id;

    expect($machine->state->matches('processing.documents.complete'))->toBeTrue();
    expect($machine->state->matches('processing.payment.complete'))->toBeTrue();

    // Restore and verify
    $restoredMachine = Machine::create($definition);
    $restoredState   = $restoredMachine->restoreStateFromRootEventId($rootEventId);

    expect($restoredState->matches('processing.documents.complete'))->toBeTrue();
    expect($restoredState->matches('processing.payment.complete'))->toBeTrue();
});

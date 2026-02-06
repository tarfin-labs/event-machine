<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('event is handled by the correct region only', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'player',
        'initial' => 'active',
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
                            'playing' => [
                                'on' => [
                                    'PAUSE' => 'paused',
                                ],
                            ],
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
                            'muted' => [
                                'on' => [
                                    'UNMUTE' => 'unmuted',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();
    expect($state->matches('active.track.paused'))->toBeTrue();
    expect($state->matches('active.volume.unmuted'))->toBeTrue();

    // PLAY should only affect track region
    $state = $definition->transition(['type' => 'PLAY'], $state);

    expect($state->matches('active.track.playing'))->toBeTrue();
    expect($state->matches('active.volume.unmuted'))->toBeTrue(); // unchanged
});

test('mute event only affects volume region', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'player',
        'initial' => 'active',
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
    ]);

    $state = $definition->getInitialState();

    // MUTE should only affect volume region
    $state = $definition->transition(['type' => 'MUTE'], $state);

    expect($state->matches('active.track.paused'))->toBeTrue(); // unchanged
    expect($state->matches('active.volume.muted'))->toBeTrue();
});

test('both regions can transition with different events', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'player',
        'initial' => 'active',
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
    ]);

    $state = $definition->getInitialState();

    // First transition - play
    $state = $definition->transition(['type' => 'PLAY'], $state);
    expect($state->matches('active.track.playing'))->toBeTrue();
    expect($state->matches('active.volume.unmuted'))->toBeTrue();

    // Second transition - mute
    $state = $definition->transition(['type' => 'MUTE'], $state);
    expect($state->matches('active.track.playing'))->toBeTrue();
    expect($state->matches('active.volume.muted'))->toBeTrue();
});

<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('entry actions fire when entering parallel regions', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test',
            'initial' => 'active',
            'context' => ['log' => []],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'regionA' => [
                            'initial' => 'a1',
                            'states'  => [
                                'a1' => [
                                    'entry' => 'logEntryA1',
                                ],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'b1',
                            'states'  => [
                                'b1' => [
                                    'entry' => 'logEntryB1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logEntryA1' => function (ContextManager $ctx) use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'entryA1';
                },
                'logEntryB1' => function (ContextManager $ctx) use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'entryB1';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Both entry actions should have been called
    expect($actionsExecuted)->toContain('entryA1');
    expect($actionsExecuted)->toContain('entryB1');
});

test('transition actions fire during parallel region transitions', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test',
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
                                        'PLAY' => [
                                            'target'  => 'playing',
                                            'actions' => 'logPlay',
                                        ],
                                    ],
                                ],
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
        ],
        behavior: [
            'actions' => [
                'logPlay' => function (ContextManager $ctx) use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'play';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'PLAY'], $state);

    expect($actionsExecuted)->toContain('play');
    expect($state->matches('active.track.playing'))->toBeTrue();
});

test('exit actions fire during parallel region transitions', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'track' => [
                            'initial' => 'paused',
                            'states'  => [
                                'paused' => [
                                    'exit' => 'logExitPaused',
                                    'on'   => [
                                        'PLAY' => 'playing',
                                    ],
                                ],
                                'playing' => [
                                    'entry' => 'logEntryPlaying',
                                ],
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
        ],
        behavior: [
            'actions' => [
                'logExitPaused' => function (ContextManager $ctx) use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'exitPaused';
                },
                'logEntryPlaying' => function (ContextManager $ctx) use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'entryPlaying';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'PLAY'], $state);

    // Exit should fire before entry
    expect($actionsExecuted)->toEqual(['exitPaused', 'entryPlaying']);
});

test('context is shared across all parallel regions', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test',
            'initial' => 'active',
            'context' => ['count' => 0],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'regionA' => [
                            'initial' => 'a1',
                            'states'  => [
                                'a1' => [
                                    'entry' => 'incrementCount',
                                ],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'b1',
                            'states'  => [
                                'b1' => [
                                    'entry' => 'incrementCount',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementCount' => function (ContextManager $ctx): void {
                    $ctx->set('count', $ctx->get('count') + 1);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Both regions should have incremented the same counter
    expect($state->context->get('count'))->toBe(2);
});

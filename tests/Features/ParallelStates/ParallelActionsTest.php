<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

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
                        'region_a' => [
                            'initial' => 'a1',
                            'states'  => [
                                'a1' => [
                                    'entry' => 'logEntryA1Action',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'b1',
                            'states'  => [
                                'b1' => [
                                    'entry' => 'logEntryB1Action',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'logEntryA1Action' => function (ContextManager $ctx) use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'entryA1';
                },
                'logEntryB1Action' => function (ContextManager $ctx) use (&$actionsExecuted): void {
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
                                            'actions' => 'logPlayAction',
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
            'context' => GenericContext::class,
            'actions' => [
                'logPlayAction' => function (ContextManager $ctx) use (&$actionsExecuted): void {
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
                                    'exit' => 'logExitPausedAction',
                                    'on'   => [
                                        'PLAY' => 'playing',
                                    ],
                                ],
                                'playing' => [
                                    'entry' => 'logEntryPlayingAction',
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
            'context' => GenericContext::class,
            'actions' => [
                'logExitPausedAction' => function (ContextManager $ctx) use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'exitPaused';
                },
                'logEntryPlayingAction' => function (ContextManager $ctx) use (&$actionsExecuted): void {
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
                        'region_a' => [
                            'initial' => 'a1',
                            'states'  => [
                                'a1' => [
                                    'entry' => 'incrementCountAction',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'b1',
                            'states'  => [
                                'b1' => [
                                    'entry' => 'incrementCountAction',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'incrementCountAction' => function (ContextManager $ctx): void {
                    $ctx->set('count', $ctx->get('count') + 1);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Both regions should have incremented the same counter
    expect($state->context->get('count'))->toBe(2);
});

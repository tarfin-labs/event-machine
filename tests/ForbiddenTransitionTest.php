<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;

test('Top-level event transition can switch from a deeply nested state to another top-level state', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'm',
            'initial' => 'a.b.c.d',
            'states'  => [
                'a' => [
                    'on' => [
                        '@event' => 'x',
                    ],
                    'states' => [
                        'b' => [
                            'states' => [
                                'c' => [
                                    'states' => [
                                        'd' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'x' => [],
            ],
        ],
    ]);

    $machine->start();

    expect($machine->state->value)->toBe(['m.a.b.c.d']);

    $machine->send(['type' => '@event']);

    expect($machine->state->value)->toBe(['m.x']);

    expect($machine->state->history->pluck('type')->toArray())
        ->toBe([
            'm.start',
            'm.state.a.b.c.d.enter',
            'm.state.a.b.c.d.entry.start',
            'm.state.a.b.c.d.entry.finish',
            '@event',
            'm.transition.a.b.c.d.@event.start',
            'm.transition.a.b.c.d.@event.finish',
            'm.state.a.b.c.d.exit.start',
            'm.state.a.b.c.d.exit.finish',
            'm.state.a.b.c.d.exit',
            'm.state.x.enter',
            'm.state.x.entry.start',
            'm.state.x.entry.finish',
        ]);
});

test('Forbidded Transition: Nested state can override top-level event transition defined in parent state', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'm',
            'initial' => 'a.b.c.d',
            'states'  => [
                'a' => [
                    'on' => [
                        '@event' => 'x',
                    ],
                    'states' => [
                        'b' => [
                            'states' => [
                                'c' => [
                                    'states' => [
                                        'd' => [
                                            'on' => [
                                                '@event' => null,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'x' => [],
            ],
        ],
    ]);

    $machine->start();

    expect($machine->state->value)->toBe(['m.a.b.c.d']);

    $machine->send(['type' => '@event']);

    expect($machine->state->value)->toBe(['m.a.b.c.d']);
});

test('Top-Level Transitions', function (): void {
    $machine = Machine::create([
        'config' => [
            'context' => [
                'value' => 0,
            ],
            'on' => [
                '@event' => [
                    'actions' => 'increaseValue',
                ],
            ],
        ],
        'behavior' => [
            'actions' => [
                'increaseValue' => function (ContextManager $context): void {
                    $context->value++;
                },
            ],
        ],
    ]);

    $machine->send(event: '@event');

    expect($machine->state->context->value)->toBe(1);
});

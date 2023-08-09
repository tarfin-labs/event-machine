<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;

test('Top-level event transition can switch from a deeply nested state to another top-level state', function (): void {
    $machine = Machine::create([
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
    ]);

    $machine->start();

    expect($machine->state->value)->toBe(['m.a.b.c.d']);

    $machine->send(['type' => '@event']);

    expect($machine->state->value)->toBe(['m.x']);
});

test('Forbidded Transition: Nested state can override top-level event transition defined in parent state', function (): void {
    $machine = Machine::create([
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
    ]);

    $machine->start();

    expect($machine->state->value)->toBe(['m.a.b.c.d']);

    $machine->send(['type' => '@event']);

    expect($machine->state->value)->toBe(['m.a.b.c.d']);
});

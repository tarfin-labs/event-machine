<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Machine;

test('a useless machine')
    ->with('useless_machines')
    ->expect(Machine::define())
    ->toBeInstanceOf(State::class)
    ->machine->toBeInstanceOf(State::class)
    ->name->toBe(State::DEFAULT_NAME)
    ->value->toBe(State::DEFAULT_NAME)
    ->path->toBe(State::DEFAULT_NAME)
    ->version->toBe(1)
    ->description->toBeNull()
    ->parent->toBeNull()
    ->initialState->toBeNull()
    ->states->toBeNull();

dataset('useless_machines', [
    'no definition' => [
        //
    ],
    'empty definition' => [
        [],
    ],
    'empty name' => [
        [
            'name' => '',
        ],
    ],
    'negative version' => [
        [
            'version' => -1,
        ],
    ],
    'zero version' => [
        [
            'version' => 0,
        ],
    ],
    'empty description' => [
        [
            'description' => '',
        ],
    ],
    'empty states' => [
        [
            'states',
        ],
    ],
    'empty array states' => [
        [
            'states' => [],
        ],
    ],
]);

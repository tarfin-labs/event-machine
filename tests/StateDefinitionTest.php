<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;

test('state definition is an instance of StateDefinition', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->root)->toBeInstanceOf(StateDefinition::class);
    expect($machineWithStates->states)->each->toBeInstanceOf(StateDefinition::class);
});

test('state definition has a machine reference', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('machine');
    expect($machine->root->machine)->toBeInstanceOf(MachineDefinition::class);
    expect($machine->root->machine)->toBe($machine);
});

test('a state definition config should reference original machine definition config', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'one',
        'states'  => [
            'one' => [
                'initial' => 'deep',
                'states'  => [
                    'deep' => [],
                ],
            ],
        ],
    ]);

    $oneState = $machine->states['one'];
    expect($oneState->config)->toBe($machine->config['states']['one']);

    $deepState = $machine->states['one']->states['deep'];
    expect($deepState->config)->toBe($machine->config['states']['one']['states']['deep']);

    // TODO: Consider that if these should be reactive?
    //$deepState->config['meta'] = 'testing meta';
    //expect($machine->config['states']['one']['states']['deep']['meta'])->toBe('testing meta');
});

test('a state definition has a key', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->root)->toHaveProperty('key');
    expect($machineWithStates->root->key)->toBe(MachineDefinition::DEFAULT_ID);

    expect($machineWithStates->states['green'])->toHaveProperty('key');
    expect($machineWithStates->states['green']->key)->toBe('green');

    expect($machineWithStates->states['yellow'])->toHaveProperty('key');
    expect($machineWithStates->states['yellow']->key)->toBe('yellow');

    expect($machineWithStates->states['red'])->toHaveProperty('key');
    expect($machineWithStates->states['red']->key)->toBe('red');
});

test('a state definition has a config', function (): void {
    $machineConfiguration = [
        'id' => 'machine_name',
    ];
    $machine = MachineDefinition::define($machineConfiguration);

    expect($machine->root)->toHaveProperty('config');
    expect($machine->root->config)->toBe($machineConfiguration);
});

test('a state definition has a null config when not provided', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('config');
    expect($machine->root->config)->toBeNull();
});

test('a state definition has a parent state definition', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->states['green'])->toHaveProperty('parent');
    expect($machineWithStates->states['green']->parent)->toBe($machineWithStates->root);

    expect($machineWithStates->states['yellow'])->toHaveProperty('parent');
    expect($machineWithStates->states['yellow']->parent)->toBe($machineWithStates->root);

    expect($machineWithStates->states['red'])->toHaveProperty('parent');
    expect($machineWithStates->states['red']->parent)->toBe($machineWithStates->root);
});

test('the parent of a state definition is null if it has no parent', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->root->parent)->toBeNull();
});

test('a state definition has a path', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->root)->toHaveProperty('path');
    expect($machineWithStates->root->path)->toBe([]);

    expect($machineWithStates->states['green'])->toHaveProperty('path');
    expect($machineWithStates->states['green']->path)->toBe(['green']);

    expect($machineWithStates->states['yellow'])->toHaveProperty('path');
    expect($machineWithStates->states['yellow']->path)->toBe(['yellow']);

    expect($machineWithStates->states['red'])->toHaveProperty('path');
    expect($machineWithStates->states['red']->path)->toBe(['red']);
});

test('a state definition can have a description', function (): void {
    $description = 'This is a description';
    $machine     = MachineDefinition::define(config: [
        'description' => $description,
    ]);

    expect($machine->root)->toHaveProperty('description');
    expect($machine->root->description)->toBe($description);
});

test('the state definition is null if not provided', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine->root)->toHaveProperty('description');
    expect($nullMachine->root->description)->toBeNull();
});

test('a state definition has an order', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->root)->toHaveProperty('order');
    expect($machineWithStates->root->order)->toBe(0);

    expect($machineWithStates->states['green'])->toHaveProperty('order');
    expect($machineWithStates->states['green']->order)->toBe(1);

    expect($machineWithStates->states['yellow'])->toHaveProperty('order');
    expect($machineWithStates->states['yellow']->order)->toBe(2);

    expect($machineWithStates->states['red'])->toHaveProperty('order');
    expect($machineWithStates->states['red']->order)->toBe(3);
});

test('a state definition has states', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->states)
        ->toBeArray()
        ->toHaveKeys(['green', 'yellow', 'red'])
        ->each->toBeInstanceOf(StateDefinition::class);
});

test('a state config can be null', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => null,
            'yellow' => null,
            'red'    => null,
        ],
    ]);

    expect($machineWithStates->states)
        ->toBeArray()
        ->toHaveKeys(['green', 'yellow', 'red'])
        ->each->toBeInstanceOf(StateDefinition::class);
});

test('a state definition can have transitions', function (): void {
    $lightMachine = MachineDefinition::define([
        'id'      => 'light-machine',
        'initial' => 'green',
        'states'  => [
            'green' => [
                'on' => [
                    'TIMER'           => 'yellow',
                    'POWER_OUTAGE'    => 'red',
                    'FORBIDDEN_EVENT' => null,
                ],
            ],
            'yellow' => [
                'on' => [
                    'TIMER'           => 'red',
                    'POWER_OUTAGE'    => 'red',
                    'FORBIDDEN_EVENT' => [
                        'target' => null,
                    ],
                ],
            ],
            'red' => [
                'on' => [
                    'TIMER'        => 'green',
                    'POWER_OUTAGE' => [
                        'target' => 'red',
                    ],
                ],
                'initial' => 'walk',
                'states'  => [
                    'walk' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'wait',
                        ],
                    ],
                    'wait' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'stop',
                        ],
                    ],
                    'stop' => [],
                ],
            ],
        ],
    ]);

    expect($lightMachine->states['green']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['yellow']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->states['walk']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->states['wait']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->states['stop']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);

    $greenTimerTransition = $lightMachine->states['green']->transitions['TIMER'];
    expect($greenTimerTransition)
        ->transitionConfig->toBe('yellow')
        ->event->toBe('TIMER')
        ->source->toBe($lightMachine->states['green'])
        ->target->toBe($lightMachine->states['yellow']);

    $yellowForbidenTransition = $lightMachine->states['yellow']->transitions['FORBIDDEN_EVENT'];
    expect($yellowForbidenTransition)
        ->transitionConfig->toMatchArray([
            'target' => null,
        ])
        ->event->toBe('FORBIDDEN_EVENT')
        ->source->toBe($lightMachine->states['yellow'])
        ->target->toBeNull();

    $redWaitPedCountdownTransition = $lightMachine->states['red']->states['wait']->transitions['PED_COUNTDOWN'];
    expect($redWaitPedCountdownTransition)
        ->transitionConfig->toBe('stop')
        ->event->toBe('PED_COUNTDOWN')
        ->source->toBe($lightMachine->states['red']->states['wait'])
        ->target->toBe($lightMachine->states['red']->states['stop']);
});

test('a state definition can have events', function (): void {
    $lightMachine = MachineDefinition::define([
        'id'      => 'light-machine',
        'initial' => 'green',
        'states'  => [
            'green' => [
                'on' => [
                    'TIMER'           => 'yellow',
                    'POWER_OUTAGE'    => 'red',
                    'FORBIDDEN_EVENT' => null,
                ],
            ],
            'yellow' => [
                'on' => [
                    'TIMER'           => 'red',
                    'POWER_OUTAGE'    => 'red',
                    'FORBIDDEN_EVENT' => [
                        'target' => null,
                    ],
                ],
            ],
            'red' => [
                'on' => [
                    'TIMER'        => 'green',
                    'POWER_OUTAGE' => [
                        'target' => 'red',
                    ],
                ],
                'initial' => 'walk',
                'states'  => [
                    'walk' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'wait',
                        ],
                    ],
                    'wait' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'stop',
                        ],
                    ],
                    'stop' => [],
                ],
            ],
        ],
    ]);

    expect($lightMachine)
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'TIMER',
            'POWER_OUTAGE',
            'FORBIDDEN_EVENT',
            'PED_COUNTDOWN',
        ]);

    expect($lightMachine->states['green'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'TIMER',
            'POWER_OUTAGE',
            'FORBIDDEN_EVENT',
        ]);

    expect($lightMachine->states['yellow'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'TIMER',
            'POWER_OUTAGE',
            'FORBIDDEN_EVENT',
        ]);

    expect($lightMachine->states['red'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'TIMER',
            'POWER_OUTAGE',
            'PED_COUNTDOWN',
        ]);

    expect($lightMachine->states['red']->states['walk'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'PED_COUNTDOWN',
        ]);

    expect($lightMachine->states['red']->states['wait'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'PED_COUNTDOWN',
        ]);

    expect($lightMachine->states['red']->states['stop'])
        ->toHaveProperty('events')
        ->events->toBeNull();
});

test('a state definition can have entry actions', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'entry' => [
                    'entryAction1',
                    'entryAction2',
                ],
            ],
            'yellow' => [
                'entry' => 'entryAction3',
            ],
            'red' => [],
        ],
    ]);

    $greenState  = $machine->states['green'];
    $yellowState = $machine->states['yellow'];
    $redState    = $machine->states['red'];

    expect($greenState->entry)->toBe(['entryAction1', 'entryAction2']);
    expect($yellowState->entry)->toBe(['entryAction3']);
    expect($redState->entry)->toBe([]);
});

test('a state definition can have exit actions', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'exit' => [
                    'exitAction1',
                    'exitAction2',
                ],
            ],
            'yellow' => [
                'exit' => 'exitAction3',
            ],
            'red' => [],
        ],
    ]);

    $greenState  = $machine->states['green'];
    $yellowState = $machine->states['yellow'];
    $redState    = $machine->states['red'];

    expect($greenState->exit)->toBe(['exitAction1', 'exitAction2']);
    expect($yellowState->exit)->toBe(['exitAction3']);
    expect($redState->exit)->toBe([]);
});

test('a state definition can have meta', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'meta' => [
                    'foo' => 'bar',
                ],
            ],
            'yellow' => [
                'meta' => [
                    'foo' => 'baz',
                ],
            ],
            'red' => [],
        ],
    ]);

    $greenState  = $machine->states['green'];
    $yellowState = $machine->states['yellow'];
    $redState    = $machine->states['red'];

    expect($greenState->meta)->toBe(['foo' => 'bar']);
    expect($yellowState->meta)->toBe(['foo' => 'baz']);
    expect($redState->meta)->toBeNull();
});

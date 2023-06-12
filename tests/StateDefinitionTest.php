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
    expect($machineWithStates->stateDefinitions)->each->toBeInstanceOf(StateDefinition::class);
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

    $oneState = $machine->stateDefinitions['one'];
    expect($oneState->config)->toBe($machine->config['states']['one']);

    $deepState = $machine->stateDefinitions['one']->stateDefinitions['deep'];
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

    expect($machineWithStates->stateDefinitions['green'])->toHaveProperty('key');
    expect($machineWithStates->stateDefinitions['green']->key)->toBe('green');

    expect($machineWithStates->stateDefinitions['yellow'])->toHaveProperty('key');
    expect($machineWithStates->stateDefinitions['yellow']->key)->toBe('yellow');

    expect($machineWithStates->stateDefinitions['red'])->toHaveProperty('key');
    expect($machineWithStates->stateDefinitions['red']->key)->toBe('red');
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

    expect($machineWithStates->stateDefinitions['green'])->toHaveProperty('parent');
    expect($machineWithStates->stateDefinitions['green']->parent)->toBe($machineWithStates->root);

    expect($machineWithStates->stateDefinitions['yellow'])->toHaveProperty('parent');
    expect($machineWithStates->stateDefinitions['yellow']->parent)->toBe($machineWithStates->root);

    expect($machineWithStates->stateDefinitions['red'])->toHaveProperty('parent');
    expect($machineWithStates->stateDefinitions['red']->parent)->toBe($machineWithStates->root);
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

    expect($machineWithStates->stateDefinitions['green'])->toHaveProperty('path');
    expect($machineWithStates->stateDefinitions['green']->path)->toBe(['green']);

    expect($machineWithStates->stateDefinitions['yellow'])->toHaveProperty('path');
    expect($machineWithStates->stateDefinitions['yellow']->path)->toBe(['yellow']);

    expect($machineWithStates->stateDefinitions['red'])->toHaveProperty('path');
    expect($machineWithStates->stateDefinitions['red']->path)->toBe(['red']);
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

    expect($machineWithStates->stateDefinitions['green'])->toHaveProperty('order');
    expect($machineWithStates->stateDefinitions['green']->order)->toBe(1);

    expect($machineWithStates->stateDefinitions['yellow'])->toHaveProperty('order');
    expect($machineWithStates->stateDefinitions['yellow']->order)->toBe(2);

    expect($machineWithStates->stateDefinitions['red'])->toHaveProperty('order');
    expect($machineWithStates->stateDefinitions['red']->order)->toBe(3);
});

test('a state definition has states', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->stateDefinitions)
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

    expect($machineWithStates->stateDefinitions)
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
                    'FORBIDDEN_EVENT_WITH_ACTIONS' => [
                        'target' => null,
                        'guards' => [
                            'guardSomething1',
                            'guardSomething2',
                        ],
                        'actions' => [
                            'doSomething1',
                            'doSomething2',
                        ],
                    ],
                ],
            ],
            'red' => [
                'initial' => 'walk',
                'on'      => [
                    'TIMER'        => 'green',
                    'POWER_OUTAGE' => ['target' => 'red'],
                ],
                'states' => [
                    'walk' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'red.wait',
                        ],
                    ],
                    'wait' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'red.stop',
                        ],
                    ],
                    'stop' => [],
                ],
            ],
        ],
    ]);

    expect($lightMachine->stateDefinitions['green']->transitionDefinitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->stateDefinitions['yellow']->transitionDefinitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->stateDefinitions['red']->transitionDefinitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->stateDefinitions['red']->stateDefinitions['walk']->transitionDefinitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->stateDefinitions['red']->stateDefinitions['wait']->transitionDefinitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->stateDefinitions['red']->stateDefinitions['stop']->transitionDefinitions)->toBeNull();

    $greenTimerTransition = $lightMachine->stateDefinitions['green']->transitionDefinitions['TIMER'];
    expect($greenTimerTransition)
        ->transitionConfig->toBe('yellow')
        ->event->toBe('TIMER')
        ->source->toBe($lightMachine->stateDefinitions['green']);

    expect($greenTimerTransition->branches[0]->target)->toBe($lightMachine->stateDefinitions['yellow']);

    $yellowForbidenTransition = $lightMachine->stateDefinitions['yellow']->transitionDefinitions['FORBIDDEN_EVENT'];
    expect($yellowForbidenTransition)
        ->event->toBe('FORBIDDEN_EVENT')
        ->source->toBe($lightMachine->stateDefinitions['yellow']);
    expect($yellowForbidenTransition->branches[0]->target)->toBe(null);

    $redWaitPedCountdownTransition = $lightMachine->stateDefinitions['red']->stateDefinitions['wait']->transitionDefinitions['PED_COUNTDOWN'];
    expect($redWaitPedCountdownTransition)
        ->transitionConfig->toBe('red.stop')
        ->event->toBe('PED_COUNTDOWN')
        ->source->toBe($lightMachine->stateDefinitions['red']->stateDefinitions['wait']);

    expect($redWaitPedCountdownTransition->branches[0]->target)->toBe($lightMachine->stateDefinitions['red']->stateDefinitions['stop']);
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

    expect($lightMachine->stateDefinitions['green'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'TIMER',
            'POWER_OUTAGE',
            'FORBIDDEN_EVENT',
        ]);

    expect($lightMachine->stateDefinitions['yellow'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'TIMER',
            'POWER_OUTAGE',
            'FORBIDDEN_EVENT',
        ]);

    expect($lightMachine->stateDefinitions['red'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'TIMER',
            'POWER_OUTAGE',
            'PED_COUNTDOWN',
        ]);

    expect($lightMachine->stateDefinitions['red']->stateDefinitions['walk'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'PED_COUNTDOWN',
        ]);

    expect($lightMachine->stateDefinitions['red']->stateDefinitions['wait'])
        ->toHaveProperty('events')
        ->events->toMatchArray([
            'PED_COUNTDOWN',
        ]);

    expect($lightMachine->stateDefinitions['red']->stateDefinitions['stop'])
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

    $greenState  = $machine->stateDefinitions['green'];
    $yellowState = $machine->stateDefinitions['yellow'];
    $redState    = $machine->stateDefinitions['red'];

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

    $greenState  = $machine->stateDefinitions['green'];
    $yellowState = $machine->stateDefinitions['yellow'];
    $redState    = $machine->stateDefinitions['red'];

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

    $greenState  = $machine->stateDefinitions['green'];
    $yellowState = $machine->stateDefinitions['yellow'];
    $redState    = $machine->stateDefinitions['red'];

    expect($greenState->meta)->toBe(['foo' => 'bar']);
    expect($yellowState->meta)->toBe(['foo' => 'baz']);
    expect($redState->meta)->toBeNull();
});

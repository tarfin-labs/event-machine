<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Results\GreenResult;

test('a state definition can be atomic', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [],
        ],
    ]);

    $yellowState = $machine->stateDefinitions['yellow'];

    expect($yellowState->type)->toBe(StateDefinitionType::ATOMIC);
});

test('a state definition can be compound', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'states' => [
                    'a' => [],
                    'b' => [],
                ],
            ],
        ],
    ]);

    $yellowState = $machine->stateDefinitions['yellow'];

    expect($yellowState->type)->toBe(StateDefinitionType::COMPOUND);
});

test('a state definition can be defined as final', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'type' => 'final',
            ],
        ],
    ]);

    $yellowState = $machine->stateDefinitions['yellow'];

    expect($yellowState->type)->toBe(StateDefinitionType::FINAL);
});

test('a machine can have outputs on final states', function (string $eventType): void {
    $now = now();
    Carbon::setTestNow($now);

    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        '@yellow' => 'yellow',
                        '@green'  => 'green',
                    ],
                ],
                'yellow' => [
                    'type'   => 'final',
                    'result' => function (): Carbon {
                        return now();
                    },
                ],
                'green' => [
                    'type'   => 'final',
                    'result' => GreenResult::class,
                ],
            ],
        ],
    ]);

    $machine->send($eventType);

    /** @var \Illuminate\Support\Carbon $result */
    $result = $machine->result();

    expect($result->toDateTimeString())->toBe($now->toDateTimeString());
})->with([
    '@yellow',
    '@green',
]);

test('an initial state of type final triggers machine finish event', function (): void {
    $machine = Machine::create(definition: [
        'config' => [
            'id'      => 'mac',
            'initial' => 'yellow',
            'states'  => [
                'yellow' => [
                    'type' => 'final',
                ],
            ],
        ],
    ]);

    expect($machine->state->history->pluck('type')->toArray())
        ->toEqual([
            'mac.start',
            'mac.state.yellow.enter',
            'mac.state.yellow.entry.start',
            'mac.state.yellow.entry.finish',
            'mac.finish',
        ]);
});

test('a state of type final triggers machine finish event', function (): void {
    $machine = Machine::withDefinition(MachineDefinition::define(config: [
        'id'      => 'dummy',
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'red',
                    ],
                ],
            ],
            'red' => [
                'type' => 'final',
            ],
        ],
    ]));

    $state = $machine->send(['type' => 'EVENT']);

    expect($state->history->pluck('type')->toArray())
        ->toEqual([
            'dummy.start',
            'dummy.state.yellow.enter',
            'dummy.state.yellow.entry.start',
            'dummy.state.yellow.entry.finish',
            'EVENT',
            'dummy.transition.yellow.EVENT.start',
            'dummy.transition.yellow.EVENT.finish',
            'dummy.state.yellow.exit.start',
            'dummy.state.yellow.exit.finish',
            'dummy.state.yellow.exit',
            'dummy.state.red.enter',
            'dummy.state.red.entry.start',
            'dummy.state.red.entry.finish',
            'dummy.finish',
        ]);
});

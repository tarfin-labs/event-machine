<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

function createParallelMachineWithOnFail(array|string $onFail = 'error'): MachineDefinition
{
    return MachineDefinition::define(config: [
        'id'      => 'test',
        'initial' => 'parallel_state',
        'states'  => [
            'parallel_state' => [
                'type'   => 'parallel',
                'onDone' => 'done',
                'onFail' => $onFail,
                'states' => [
                    'region_a' => [
                        'initial' => 'working_a',
                        'states'  => [
                            'working_a'  => [],
                            'finished_a' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working_b',
                        'states'  => [
                            'working_b'  => [],
                            'finished_b' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done'  => ['type' => 'final'],
            'error' => ['type' => 'final'],
        ],
    ]);
}

it('processParallelOnFail transitions to onFail target', function (): void {
    $machine       = createParallelMachineWithOnFail();
    $state         = $machine->getInitialState();
    $parallelState = $machine->idMap['test.parallel_state'];

    $result = $machine->processParallelOnFail($parallelState, $state);

    expect($result)->toBeInstanceOf(State::class);
    expect($result->value)->toBe(['test.error']);
});

it('processParallelOnFail records PARALLEL_FAIL internal event', function (): void {
    $machine       = createParallelMachineWithOnFail();
    $state         = $machine->getInitialState();
    $parallelState = $machine->idMap['test.parallel_state'];

    $result = $machine->processParallelOnFail($parallelState, $state);

    $eventTypes = $result->history->pluck('type')->toArray();
    expect($eventTypes)->toContain('test.parallel.parallel_state.fail');
});

it('processParallelOnFail without onFail records event and stays in parallel', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'      => 'test',
        'initial' => 'parallel_state',
        'states'  => [
            'parallel_state' => [
                'type'   => 'parallel',
                'onDone' => 'done',
                // No onFail defined
                'states' => [
                    'region_a' => [
                        'initial' => 'working_a',
                        'states'  => [
                            'working_a'  => [],
                            'finished_a' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working_b',
                        'states'  => [
                            'working_b'  => [],
                            'finished_b' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $state         = $machine->getInitialState();
    $parallelState = $machine->idMap['test.parallel_state'];

    $result = $machine->processParallelOnFail($parallelState, $state);

    // Should stay in parallel state (no transition)
    expect($result->value)->toBe(['test.parallel_state.region_a.working_a', 'test.parallel_state.region_b.working_b']);

    $eventTypes = $result->history->pluck('type')->toArray();
    expect($eventTypes)->toContain('test.parallel.parallel_state.fail');
});

it('processParallelOnFail with eventBehavior=null works', function (): void {
    $machine       = createParallelMachineWithOnFail();
    $state         = $machine->getInitialState();
    $parallelState = $machine->idMap['test.parallel_state'];

    // Explicitly pass null — should not throw
    $result = $machine->processParallelOnFail($parallelState, $state, null);

    expect($result->value)->toBe(['test.error']);
});

it('processParallelOnFail with onFail as string works', function (): void {
    $machine       = createParallelMachineWithOnFail('error');
    $state         = $machine->getInitialState();
    $parallelState = $machine->idMap['test.parallel_state'];

    $result = $machine->processParallelOnFail($parallelState, $state);

    expect($result->value)->toBe(['test.error']);
});

it('processParallelOnFail with onFail as array works', function (): void {
    $machine       = createParallelMachineWithOnFail(['target' => 'error']);
    $state         = $machine->getInitialState();
    $parallelState = $machine->idMap['test.parallel_state'];

    $result = $machine->processParallelOnFail($parallelState, $state);

    expect($result->value)->toBe(['test.error']);
});

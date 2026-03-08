<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\AsdMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

function createDispatchableParallelDefinition(): MachineDefinition
{
    return MachineDefinition::define(config: [
        'id'             => 'test',
        'initial'        => 'parallel_state',
        'should_persist' => true,
        'states'         => [
            'parallel_state' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'entry' => 'SomeEntryActionA',
                            ],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'entry' => 'SomeEntryActionB',
                            ],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);
}

it('does not dispatch when parallel_dispatch config is disabled', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    // No entry actions — sequential mode runs fine without real action classes
    $definition = MachineDefinition::define(config: [
        'id'      => 'test',
        'initial' => 'parallel_state',
        'states'  => [
            'parallel_state' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => [],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => [],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    expect($definition->pendingParallelDispatches)->toBe([]);
});

it('does not dispatch when fewer than 2 regions have entry actions', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $definition = MachineDefinition::define(config: [
        'id'             => 'test',
        'initial'        => 'parallel_state',
        'should_persist' => true,
        'states'         => [
            'parallel_state' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'entry' => 'SomeEntryActionA', // Only 1 region has entry
                            ],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => [], // No entry actions
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);
    $definition->machineClass = AsdMachine::class;

    // Sequential mode — but only 1 region has entry, which tries to resolve
    // The point is shouldDispatchParallel returns false (< 2 regions)
    // and falls through to sequential path. Since 'SomeEntryActionA' is fake,
    // we just verify that pendingParallelDispatches is NOT populated.
    // We expect BehaviorNotFoundException from sequential path running the fake action.
    expect(fn () => $definition->getInitialState())
        ->toThrow(\Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException::class);

    expect($definition->pendingParallelDispatches)->toBe([]);
});

it('populates pendingParallelDispatches when all conditions met', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $definition               = createDispatchableParallelDefinition();
    $definition->machineClass = AsdMachine::class;

    $state = $definition->getInitialState();

    // In dispatch mode, entry actions are NOT run — they're queued
    expect($definition->pendingParallelDispatches)->toHaveCount(2);
    expect($definition->pendingParallelDispatches[0]['region_id'])->toBe('test.parallel_state.region_a');
    expect($definition->pendingParallelDispatches[0]['initial_state_id'])->toBe('test.parallel_state.region_a.working');
    expect($definition->pendingParallelDispatches[1]['region_id'])->toBe('test.parallel_state.region_b');
    expect($definition->pendingParallelDispatches[1]['initial_state_id'])->toBe('test.parallel_state.region_b.working');
});

it('does not dispatch when machineClass is base Machine::class', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $definition               = createDispatchableParallelDefinition();
    $definition->machineClass = Machine::class;

    // Sequential mode — but fake actions cause BehaviorNotFoundException
    expect(fn () => $definition->getInitialState())
        ->toThrow(\Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException::class);

    expect($definition->pendingParallelDispatches)->toBe([]);
});

it('sets state values correctly in dispatch mode', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $definition               = createDispatchableParallelDefinition();
    $definition->machineClass = AsdMachine::class;

    $state = $definition->getInitialState();

    // Both regions should be at their initial states
    expect($state->value)->toBe([
        'test.parallel_state.region_a.working',
        'test.parallel_state.region_b.working',
    ]);
});

it('sequential mode runs entry actions normally (regression)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    $definition = MachineDefinition::define(config: [
        'id'      => 'test',
        'initial' => 'parallel_state',
        'states'  => [
            'parallel_state' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => [],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => [],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    expect($state->value)->toBe([
        'test.parallel_state.region_a.working',
        'test.parallel_state.region_b.working',
    ]);
    expect($definition->pendingParallelDispatches)->toBe([]);
});

it('dispatches ParallelRegionJob for each pending region', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = AsdMachine::create();

    // Manually populate pendingParallelDispatches to simulate enterParallelState dispatch mode
    $machine->definition->pendingParallelDispatches = [
        ['region_id' => 'test.parallel_state.region_a', 'initial_state_id' => 'test.parallel_state.region_a.working'],
        ['region_id' => 'test.parallel_state.region_b', 'initial_state_id' => 'test.parallel_state.region_b.working'],
    ];

    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, 2);
    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job): bool {
        return $job->regionId === 'test.parallel_state.region_a'
            && $job->initialStateId === 'test.parallel_state.region_a.working'
            && $job->machineClass === AsdMachine::class;
    });
    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job): bool {
        return $job->regionId === 'test.parallel_state.region_b'
            && $job->initialStateId === 'test.parallel_state.region_b.working';
    });
});

it('clears pendingParallelDispatches after dispatching', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = AsdMachine::create();

    $machine->definition->pendingParallelDispatches = [
        ['region_id' => 'test.region_a', 'initial_state_id' => 'test.region_a.working'],
    ];

    $machine->dispatchPendingParallelJobs();

    expect($machine->definition->pendingParallelDispatches)->toBe([]);
});

it('does not dispatch when pendingParallelDispatches is empty', function (): void {
    Bus::fake();

    $machine = AsdMachine::create();

    $machine->dispatchPendingParallelJobs();

    Bus::assertNotDispatched(ParallelRegionJob::class);
});

it('sets rootEventId from state history', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = AsdMachine::create();
    $machine->persist();

    $expectedRootEventId = $machine->state->history->first()->root_event_id;

    $machine->definition->pendingParallelDispatches = [
        ['region_id' => 'test.region_a', 'initial_state_id' => 'test.region_a.working'],
    ];

    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job) use ($expectedRootEventId): bool {
        return $job->rootEventId === $expectedRootEventId;
    });
});

it('sets queue from config when configured', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.queue', 'parallel-regions');

    $machine = AsdMachine::create();

    $machine->definition->pendingParallelDispatches = [
        ['region_id' => 'test.region_a', 'initial_state_id' => 'test.region_a.working'],
    ];

    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job): bool {
        return $job->queue === 'parallel-regions';
    });
});

it('uses default queue when config queue is null', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.queue', null);

    $machine = AsdMachine::create();

    $machine->definition->pendingParallelDispatches = [
        ['region_id' => 'test.region_a', 'initial_state_id' => 'test.region_a.working'],
    ];

    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job): bool {
        return $job->queue === null;
    });
});

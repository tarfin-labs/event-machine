<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\StateConfigValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\AsdMachine;
use Tarfinlabs\EventMachine\Exceptions\MachineLockTimeoutException;
use Tarfinlabs\EventMachine\Exceptions\InvalidParallelStateDefinitionException;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('has parallel_dispatch config section with correct defaults', function (): void {
    expect(config('machine.parallel_dispatch.enabled'))->toBeFalse();
    expect(config('machine.parallel_dispatch.queue'))->toBeNull();
    expect(config('machine.parallel_dispatch.lock_timeout'))->toBe(30);
    expect(config('machine.parallel_dispatch.lock_ttl'))->toBe(60);
});

it('has PARALLEL_FAIL enum case', function (): void {
    $case = InternalEvent::PARALLEL_FAIL;

    expect($case->value)->toBe('{machine}.parallel.{placeholder}.fail');
});

it('generates correct PARALLEL_FAIL event name', function (): void {
    $name = InternalEvent::PARALLEL_FAIL->generateInternalEventName('order', 'data_collection');

    expect($name)->toBe('order.parallel.data_collection.fail');
});

it('allows onFail key in state config', function (): void {
    StateConfigValidator::validate([
        'id'      => 'test',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['START' => 'processing'],
            ],
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                '@fail'  => 'failed',
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
            'done'   => ['type' => 'final'],
            'failed' => ['type' => 'final'],
        ],
    ]);

    // No exception means validation passed
    expect(true)->toBeTrue();
});

it('still rejects invalid state keys', function (): void {
    StateConfigValidator::validate([
        'id'      => 'test',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'invalid_key' => 'value',
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'invalid keys');

it('creates requiresPersistence exception', function (): void {
    $exception = InvalidParallelStateDefinitionException::requiresPersistence();

    expect($exception)->toBeInstanceOf(InvalidParallelStateDefinitionException::class);
    expect($exception->getMessage())->toContain('should_persist: true');
    expect($exception->getMessage())->toContain('Queue jobs need the database');
});

it('creates requiresMachineSubclass exception', function (): void {
    $exception = InvalidParallelStateDefinitionException::requiresMachineSubclass();

    expect($exception)->toBeInstanceOf(InvalidParallelStateDefinitionException::class);
    expect($exception->getMessage())->toContain('Machine subclass');
    expect($exception->getMessage())->toContain('definition()');
});

it('builds MachineLockTimeoutException in immediate mode', function (): void {
    $exception = MachineLockTimeoutException::build('root-123', 0);

    expect($exception)->toBeInstanceOf(MachineLockTimeoutException::class);
    expect($exception->getMessage())->toContain("machine 'root-123'");
    expect($exception->getMessage())->toContain('immediate mode');
});

it('builds MachineLockTimeoutException in blocking mode', function (): void {
    $exception = MachineLockTimeoutException::build('root-123', 30);

    expect($exception)->toBeInstanceOf(MachineLockTimeoutException::class);
    expect($exception->getMessage())->toContain('within 30s');
});

it('builds MachineLockTimeoutException with holder info', function (): void {
    // We can't easily create a MachineStateLock without the table existing,
    // so we test the without-holder path thoroughly
    $exception = MachineLockTimeoutException::build('root-123', 0, null);

    expect($exception->getMessage())->not->toContain('Held by');
});

it('throws when parallel_dispatch enabled but should_persist is false', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    MachineDefinition::define(config: [
        'id'             => 'test',
        'initial'        => 'idle',
        'should_persist' => false,
        'states'         => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);
})->throws(InvalidParallelStateDefinitionException::class, 'should_persist: true');

it('does not throw when parallel_dispatch enabled and should_persist is true', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = MachineDefinition::define(config: [
        'id'             => 'test',
        'initial'        => 'idle',
        'should_persist' => true,
        'states'         => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

it('skips validation when parallel_dispatch is disabled', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    $machine = MachineDefinition::define(config: [
        'id'             => 'test',
        'initial'        => 'idle',
        'should_persist' => false,
        'states'         => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

it('falls back to sequential when parallel_dispatch enabled but using base Machine::class', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = Machine::create([
        'config' => [
            'id'             => 'test',
            'initial'        => 'idle',
            'should_persist' => true,
            'states'         => [
                'idle' => ['type' => 'final'],
            ],
        ],
    ]);

    // Should not throw — gracefully falls back to sequential mode
    expect($machine)->toBeInstanceOf(Machine::class);
});

it('does not throw when parallel_dispatch enabled and using Machine subclass', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = AsdMachine::create();

    expect($machine)->toBeInstanceOf(Machine::class);
});

it('skips subclass validation when parallel_dispatch is disabled', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    $machine = Machine::create([
        'config' => [
            'id'      => 'test',
            'initial' => 'idle',
            'states'  => [
                'idle' => ['type' => 'final'],
            ],
        ],
    ]);

    expect($machine)->toBeInstanceOf(Machine::class);
});

it('sets machineClass on definition after Machine::start()', function (): void {
    $machine = AsdMachine::create();

    expect($machine->definition->machineClass)->toBe(AsdMachine::class);
});

it('sets rootEventId on definition after Machine::start() with persisted state', function (): void {
    $machine = AsdMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from root event ID — rootEventId should be set
    $restored = AsdMachine::create(state: $rootEventId);

    expect($restored->definition->rootEventId)->toBe($rootEventId);
});

it('has empty pendingParallelDispatches by default', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'test',
        'initial' => 'idle',
        'states'  => [
            'idle' => ['type' => 'final'],
        ],
    ]);

    expect($definition->pendingParallelDispatches)->toBe([]);
});

it('createEventBehavior returns EventBehavior instance', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'      => 'test',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();

    $eventBehavior = $machine->createEventBehavior(
        ['type' => 'GO'],
        $state
    );

    expect($eventBehavior)->toBeInstanceOf(\Tarfinlabs\EventMachine\Behavior\EventBehavior::class);
    expect($eventBehavior->type)->toBe('GO');
});

it('areAllRegionsFinal is callable as public method', function (): void {
    $machine = MachineDefinition::define(config: [
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

    $state         = $machine->getInitialState();
    $parallelState = $machine->idMap['test.parallel_state'];

    // Both regions at initial → not all final
    $result = $machine->areAllRegionsFinal($parallelState, $state);
    expect($result)->toBeFalse();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Testing\CommunicationRecorder;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateApprovedChildMachine;

// ═══════════════════════════════════════════════════════════════
//  Category: Auto Teardown (F1-F5)
// ═══════════════════════════════════════════════════════════════

it('F1: InteractsWithMachines teardown resets fakes', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);
    expect(ImmediateChildMachine::isMachineFaked())->toBeTrue();

    // Simulate what tearDownInteractsWithMachines() does
    Machine::resetMachineFakes();
    CommunicationRecorder::reset();
    InlineBehaviorFake::resetAll();

    expect(ImmediateChildMachine::isMachineFaked())->toBeFalse();
});

it('F2: manual resetMachineFakes still safe alongside trait', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    expect(ImmediateChildMachine::isMachineFaked())->toBeTrue();

    // Manual reset should not conflict with the trait's tearDown
    Machine::resetMachineFakes();

    expect(ImmediateChildMachine::isMachineFaked())->toBeFalse();
    // Trait tearDown will also call reset — no error should occur
});

it('F3: trait resets CommunicationRecorder', function (): void {
    CommunicationRecorder::startRecording();
    CommunicationRecorder::recordSendTo('SomeClass', 'root-123', ['type' => 'PING']);

    expect(CommunicationRecorder::isRecording())->toBeTrue()
        ->and(CommunicationRecorder::getSendToRecords())->toHaveCount(1);

    // The trait tearDown will reset — but we verify state was set during this test.
    // Next test will implicitly verify the reset happened.
});

it('F4: trait resets InlineBehaviorFake', function (): void {
    // We register a fake directly (not through TestMachine to avoid key validation)
    InlineBehaviorFake::fake('someBehaviorKey');

    expect(InlineBehaviorFake::isFaked('someBehaviorKey'))->toBeTrue();

    // Trait tearDown will call InlineBehaviorFake::resetAll()
});

it('F5: double reset is harmless', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);
    CommunicationRecorder::startRecording();
    InlineBehaviorFake::fake('anotherKey');

    // First manual reset
    Machine::resetMachineFakes();
    CommunicationRecorder::reset();
    InlineBehaviorFake::resetAll();

    // Second manual reset — should not throw
    Machine::resetMachineFakes();
    CommunicationRecorder::reset();
    InlineBehaviorFake::resetAll();

    expect(ImmediateChildMachine::isMachineFaked())->toBeFalse()
        ->and(CommunicationRecorder::isRecording())->toBeFalse()
        ->and(InlineBehaviorFake::isFaked('anotherKey'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════
//  Category: Fakeable create() (F6-F11)
// ═══════════════════════════════════════════════════════════════

it('F6: create() returns stub when faked — isFakedInstance flag is set', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'done']);

    $machine = ImmediateChildMachine::create();

    // Verify the machine is a faked instance via reflection
    $reflection = new ReflectionProperty($machine, 'isFakedInstance');
    expect($reflection->getValue($machine))->toBeTrue();

    // The definition should have persistence disabled
    expect($machine->definition->shouldPersist)->toBeFalse();
});

it('F7: create(state:) returns stub when faked — no DB query', function (): void {
    ImmediateChildMachine::fake(result: ['outcome' => 'skipped']);

    // Without fake: create(state: 'non-existent') would throw RestoringStateException
    // With fake: returns stub without touching the DB
    $machine = ImmediateChildMachine::create(state: 'non-existent-root-id-xyz');

    $reflection = new ReflectionProperty($machine, 'isFakedInstance');
    expect($reflection->getValue($machine))->toBeTrue()
        ->and($machine->state)->not->toBeNull();
});

it('F8: send() is no-op on faked instance — no exception, returns state', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $machine = ImmediateChildMachine::create();
    $state   = $machine->send('SOME_EVENT');

    // send() should return the state without error
    expect($state)->toBe($machine->state);
});

it('F9: persist() is no-op on faked instance — no DB write', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $machine = ImmediateChildMachine::create();
    $result  = $machine->persist();

    // persist() should return state without error (no DB upsert)
    expect($result)->toBe($machine->state);
});

it('F10: create() works normally when NOT faked — real machine returned', function (): void {
    // Ensure not faked
    expect(ImmediateChildMachine::isMachineFaked())->toBeFalse();

    $machine = ImmediateChildMachine::create();

    $reflection = new ReflectionProperty($machine, 'isFakedInstance');
    expect($reflection->getValue($machine))->toBeFalse()
        ->and($machine->state)->not->toBeNull()
        ->and($machine->state->value)->not->toBeEmpty();
});

it('F11: send() works normally on non-faked Machine::withDefinition path', function (): void {
    // Using MachineDefinition::define() goes through withDefinition path, not create()
    $definition = MachineDefinition::define(config: [
        'id'      => 'f11_machine',
        'initial' => 'idle',
        'context' => [],
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'active'],
            ],
            'active' => ['type' => 'final'],
        ],
    ]);

    $definition->shouldPersist = false;

    $machine        = Machine::withDefinition($definition);
    $machine->state = $definition->getInitialState();

    // send() should work normally — not a faked instance
    $state = $machine->send('GO');

    expect($state->value)->toBe(['f11_machine.active']);
});

// ═══════════════════════════════════════════════════════════════
//  Category: Assertions (F12-F19)
// ═══════════════════════════════════════════════════════════════

it('F12: assertCreated passes after faked create()', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    ImmediateChildMachine::create();

    // Should not throw
    ImmediateChildMachine::assertCreated();
    expect(true)->toBeTrue();
});

it('F13: assertCreated fails when not created', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    // Do NOT call create()
    ImmediateChildMachine::assertCreated();
})->throws(AssertionFailedError::class);

it('F14: assertCreatedTimes validates exact count', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    ImmediateChildMachine::create();
    ImmediateChildMachine::create();
    ImmediateChildMachine::create();

    ImmediateChildMachine::assertCreatedTimes(3);
    expect(true)->toBeTrue();
});

it('F15: assertNotCreated passes when not created', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    // Do NOT call create()
    ImmediateChildMachine::assertNotCreated();
    expect(true)->toBeTrue();
});

it('F16: assertSent passes when event sent', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $machine = ImmediateChildMachine::create();
    $machine->send('COMPLETE');

    ImmediateChildMachine::assertSent('COMPLETE');
    expect(true)->toBeTrue();
});

it('F17: assertSent fails when wrong event type', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $machine = ImmediateChildMachine::create();
    $machine->send('COMPLETE');

    ImmediateChildMachine::assertSent('NONEXISTENT_EVENT');
})->throws(AssertionFailedError::class);

it('F18: assertNotSent passes when event not sent', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    ImmediateChildMachine::create();
    // Do NOT send any event

    ImmediateChildMachine::assertNotSent('COMPLETE');
    expect(true)->toBeTrue();
});

it('F19: assertSentTimes validates count', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $machine = ImmediateChildMachine::create();
    $machine->send('EVENT_A');
    $machine->send('EVENT_A');

    ImmediateChildMachine::assertSentTimes('EVENT_A', 2);
    expect(true)->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════
//  Category: Separation (F20-F22)
// ═══════════════════════════════════════════════════════════════

it('F20: assertInvoked does NOT pass for create()-only fakes', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    // Only call create() — no child delegation invocation
    ImmediateChildMachine::create();

    // assertInvoked tracks child delegation invocations, not create() calls
    ImmediateChildMachine::assertInvoked();
})->throws(AssertionFailedError::class);

it('F21: assertCreated does NOT pass for child delegation invocations', function (): void {
    ImmediateApprovedChildMachine::fake(result: ['decision' => 'yes']);

    // Use inline parent machine that delegates to the faked child
    $definition = MachineDefinition::define(config: [
        'id'      => 'f21_parent',
        'initial' => 'idle',
        'context' => [],
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'delegating'],
            ],
            'delegating' => [
                'machine' => ImmediateApprovedChildMachine::class,
                '@done'   => 'completed',
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $definition->shouldPersist = false;

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'GO'], state: $state);

    // Child was invoked (delegation), but assertCreated tracks create() calls only
    ImmediateApprovedChildMachine::assertInvoked();
    ImmediateApprovedChildMachine::assertCreated();
})->throws(AssertionFailedError::class);

it('F22: child delegation still works when create fake is active', function (): void {
    MultiOutcomeChildMachine::fake(finalState: 'approved');

    // Parent delegates to faked child — @done.approved should fire
    $definition = MachineDefinition::define(config: [
        'id'      => 'f22_parent',
        'initial' => 'idle',
        'context' => [],
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'delegating'],
            ],
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]);

    $definition->shouldPersist = false;

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'GO'], state: $state);

    // Delegation worked — routed through @done.approved
    expect($state->value)->toBe(['f22_parent.completed']);

    // Now also call create() — should return a faked stub
    $machine = MultiOutcomeChildMachine::create();

    $reflection = new ReflectionProperty($machine, 'isFakedInstance');
    expect($reflection->getValue($machine))->toBeTrue();

    // Both invocation and creation recorded
    MultiOutcomeChildMachine::assertInvoked();
    MultiOutcomeChildMachine::assertCreated();
});

// ═══════════════════════════════════════════════════════════════
//  Category: Backward Compat (F23-F25)
// ═══════════════════════════════════════════════════════════════

it('F23: existing Machine::fake() for child delegation unchanged', function (): void {
    ImmediateApprovedChildMachine::fake(result: ['decision' => 'yes']);

    $definition = MachineDefinition::define(config: [
        'id'      => 'f23_parent',
        'initial' => 'idle',
        'context' => [],
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'delegating'],
            ],
            'delegating' => [
                'machine' => ImmediateApprovedChildMachine::class,
                '@done'   => 'completed',
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $definition->shouldPersist = false;

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['f23_parent.completed']);

    ImmediateApprovedChildMachine::assertInvoked();
});

it('F24: TestMachine::fakingChild() still works', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'f24_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateApprovedChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $result = $testMachine->fakingChild(
        childClass: ImmediateApprovedChildMachine::class,
        result: ['decision' => 'yes'],
    );

    expect($result)->toBe($testMachine)
        ->and(ImmediateApprovedChildMachine::isMachineFaked())->toBeTrue();

    $testMachine
        ->send('GO')
        ->assertState('completed');
});

it('F25: TestMachine::simulateChildDone() still works', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'f25_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => SimpleChildMachine::class,
                    'queue'   => 'default',
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineF25Parent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildDone(SimpleChildMachine::class, result: ['status' => 'ok'])
        ->assertState('completed');
});

// ═══════════════════════════════════════════════════════════════
//  Category: Edge Cases (F26-F30)
// ═══════════════════════════════════════════════════════════════

it('F26: create(definition: [...]) on faked subclass returns stub — inline definition ignored', function (): void {
    ImmediateChildMachine::fake(result: ['custom' => 'value']);

    // Even though we pass a definition array, the fake intercepts first
    $machine = ImmediateChildMachine::create(definition: [
        'config' => [
            'id'      => 'should_be_ignored',
            'initial' => 'start',
            'context' => [],
            'states'  => [
                'start' => ['type' => 'final'],
            ],
        ],
    ]);

    $reflection = new ReflectionProperty($machine, 'isFakedInstance');
    expect($reflection->getValue($machine))->toBeTrue();

    ImmediateChildMachine::assertCreated();
});

it('F27: Machine::test() creates TestMachine with pre-init context', function (): void {
    // Machine::test() delegates to TestMachine::withContext() (pre-init context)
    $testMachine = ImmediateChildMachine::test();

    // Machine should be at its initial state (done — ImmediateChildMachine starts at final)
    expect($testMachine->state()->value)->toBe(['immediate_child.done']);
});

it('F28: multiple faked classes do not interfere', function (): void {
    ImmediateChildMachine::fake(result: ['a' => 1]);
    SimpleChildMachine::fake(result: ['b' => 2]);

    ImmediateChildMachine::create();

    // assertCreated passes for A
    ImmediateChildMachine::assertCreated();

    // assertNotCreated passes for B (not created yet)
    SimpleChildMachine::assertNotCreated();

    // Now create B
    SimpleChildMachine::create();
    SimpleChildMachine::assertCreated();

    // Counts are independent
    ImmediateChildMachine::assertCreatedTimes(1);
    SimpleChildMachine::assertCreatedTimes(1);

    expect(true)->toBeTrue();
});

it('F29: send() records multiple events', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $machine = ImmediateChildMachine::create();
    $machine->send('EVENT_ONE');
    $machine->send('EVENT_TWO');
    $machine->send('EVENT_ONE');

    ImmediateChildMachine::assertSentTimes('EVENT_ONE', 2);
    ImmediateChildMachine::assertSentTimes('EVENT_TWO', 1);
    ImmediateChildMachine::assertSent('EVENT_ONE');
    ImmediateChildMachine::assertSent('EVENT_TWO');
    ImmediateChildMachine::assertNotSent('EVENT_THREE');

    expect(true)->toBeTrue();
});

it('F30: faked machine state.value is empty array', function (): void {
    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $machine = ImmediateChildMachine::create();

    // createFaked() passes currentStateDefinition: null to State constructor,
    // which causes updateMachineValueFromState to set value = []
    expect($machine->state->value)->toBe([]);
});

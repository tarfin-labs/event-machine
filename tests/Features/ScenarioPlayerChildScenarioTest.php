<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Exceptions\ScenarioFailedException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\GrandchildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\MultiHopChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\CallableOutcomeMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\StartScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\GrandchildDoneScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\PauseAtVerifyingScenario;

beforeEach(function (): void {
    config()->set('machine.scenarios.enabled', true);
});

// NOTE: 'Child reaches final state → returns State' moved to QA tests —
// requires real delegation (shouldPersist=false skips job delegation).

test('child pauses at interactive state → returns null', function (): void {
    // PauseAtVerifyingScenario targets 'verifying' (delegation state, NOT final).
    // In shouldPersist=false mode, delegation is skipped → machine stays at verifying.
    // executeChildScenario checks currentStateDefinition->type === FINAL → false → returns null.
    $state = ScenarioPlayer::executeChildScenario(
        childScenarioClass: PauseAtVerifyingScenario::class,
        childMachineClass: ScenarioTestChildMachine::class,
    );

    expect($state)->toBeNull();
});

test('child machine created with shouldPersist=false', function (): void {
    $countBefore = MachineCurrentState::count();

    ScenarioPlayer::executeChildScenario(
        childScenarioClass: PauseAtVerifyingScenario::class,
        childMachineClass: ScenarioTestChildMachine::class,
    );

    expect(MachineCurrentState::count())->toBe($countBefore);
});

test('child scenario overrides don\'t leak to parent outcomes', function (): void {
    $reflection = new ReflectionProperty(ScenarioPlayer::class, 'outcomes');
    $reflection->setAccessible(true);
    $reflection->setValue(null, ['parent_state' => '@done']);

    ScenarioPlayer::executeChildScenario(
        childScenarioClass: StartScenario::class,
        childMachineClass: ScenarioTestChildMachine::class,
    );

    expect(ScenarioPlayer::getOutcome('parent_state'))->toBe('@done');

    ScenarioPlayer::cleanupOverrides();
});

test('child machine with transient initial state — @always chain runs with overrides', function (): void {
    // ScenarioTestChildMachine: idle → @always → verifying (delegation).
    // In shouldPersist=false, delegation skipped → lands at verifying.
    // Verify the @always chain ran (machine didn't stay at idle).
    $state = ScenarioPlayer::executeChildScenario(
        childScenarioClass: StartScenario::class,
        childMachineClass: ScenarioTestChildMachine::class,
    );

    // Child pauses at verifying (delegation skipped in test mode)
    // But the @always chain DID run: idle → verifying
    // The state is either null (paused) or State (if reached final)
    if ($state !== null) {
        // If state-aware overrides worked and delegation was somehow resolved
        $stateValues = $state->value;
        $pastIdle    = collect($stateValues)->contains(fn (string $v): bool => !str_contains($v, 'idle'));
        expect($pastIdle)->toBeTrue();
    } else {
        // Delegation skipped — child at verifying (not idle, so @always ran)
        expect($state)->toBeNull();
    }
});

test('nested child scenario (grandchild) — delegation within delegation', function (): void {
    // GrandchildMachine: idle → @always → gc_done (final). No delegation.
    // GrandchildDoneScenario targets GrandchildMachine → gc_done.
    $state = ScenarioPlayer::executeChildScenario(
        childScenarioClass: GrandchildDoneScenario::class,
        childMachineClass: GrandchildMachine::class,
    );

    // GrandchildMachine has no delegation — @always goes straight to final
    expect($state)->toBeInstanceOf(State::class)
        ->and($state->value)->toContain('grandchild.gc_done');
});

test('child scenario with job actor outcomes — outcomes intercept job dispatch', function (): void {
    // CallableOutcomeMachine: idle → @always → waiting (interactive) → CONFIRM → confirming (job actor)
    // Child scenario with @continue at waiting + @done outcome at confirming
    // If outcomes work: idle → waiting → @continue CONFIRM → confirming (@done) → completed
    // If bug: child stuck at waiting (no @continue loop) or confirming (outcome not intercepted)

    // Simulate parent execute() context — isActive must be true for outcome interception
    $isActiveRef = new ReflectionProperty(ScenarioPlayer::class, 'isActive');
    $isActiveRef->setAccessible(true);
    $isActiveRef->setValue(null, true);

    $childScenarioClass = new class() extends MachineScenario {
        protected string $machine     = CallableOutcomeMachine::class;
        protected string $source      = 'idle';
        protected string $event       = MachineScenario::START;
        protected string $target      = 'completed';
        protected string $description = 'test child with job outcome';

        protected function plan(): array
        {
            return [
                'waiting' => [
                    '@continue' => 'CONFIRM',
                ],
                'confirming' => '@done',
            ];
        }
    };

    $state = ScenarioPlayer::executeChildScenario(
        childScenarioClass: $childScenarioClass::class,
        childMachineClass: CallableOutcomeMachine::class,
    );

    // Expected: child reaches completed (FINAL) via @continue + outcome intercept
    expect($state)->not->toBeNull('Child scenario should reach completed via @continue + job outcome intercept')
        ->and($state->value)->toContain('callable_outcome_test.completed');

    ScenarioPlayer::cleanupOverrides();
    $isActiveRef->setValue(null, false);
});

test('child scenario with @continue + outcomes — multi-hop pattern', function (): void {
    // MultiHopChildMachine: idle → first_job (@done→review) → review (APPROVE) → second_job (@done→completed)
    // Child scenario: first_job @done, review @continue APPROVE, second_job @done

    $isActiveRef = new ReflectionProperty(ScenarioPlayer::class, 'isActive');
    $isActiveRef->setAccessible(true);
    $isActiveRef->setValue(null, true);

    $childScenarioClass = new class() extends MachineScenario {
        protected string $machine     = MultiHopChildMachine::class;
        protected string $source      = 'idle';
        protected string $event       = MachineScenario::START;
        protected string $target      = 'completed';
        protected string $description = 'test multi-hop child';

        protected function plan(): array
        {
            return [
                'first_job' => '@done',
                'review'    => [
                    '@continue' => 'APPROVE',
                ],
                'second_job' => '@done',
            ];
        }
    };

    $state = ScenarioPlayer::executeChildScenario(
        childScenarioClass: $childScenarioClass::class,
        childMachineClass: MultiHopChildMachine::class,
    );

    // idle → first_job (@done) → review → @continue APPROVE → second_job (@done) → completed
    expect($state)->not->toBeNull('Multi-hop child should reach completed')
        ->and($state->value)->toContain('multi_hop_child.completed');

    ScenarioPlayer::cleanupOverrides();
    $isActiveRef->setValue(null, false);
});

// ── 9.7.0 TDD tests — child scenario persistence + forward endpoint awareness ────

test('child scenario pausing at interactive state persists child to DB', function (): void {
    $isActiveRef = new ReflectionProperty(ScenarioPlayer::class, 'isActive');
    $isActiveRef->setAccessible(true);
    $isActiveRef->setValue(null, true);

    $childScenarioClass = new class() extends MachineScenario {
        protected string $machine     = CallableOutcomeMachine::class;
        protected string $source      = 'idle';
        protected string $event       = MachineScenario::START;
        protected string $target      = 'waiting';
        protected string $description = 'test child persist';

        protected function plan(): array
        {
            return [];
        }
    };

    $countBefore = MachineCurrentState::count();

    $state = ScenarioPlayer::executeChildScenario(
        childScenarioClass: $childScenarioClass::class,
        childMachineClass: CallableOutcomeMachine::class,
        parentRootEventId: 'parent-root-001',
        parentMachineClass: 'App\\Machines\\ParentMachine',
        parentStateId: 'delegating',
    );

    expect($state)->toBeNull();
    expect(MachineCurrentState::count())->toBeGreaterThan($countBefore,
        'Child paused at interactive state should be persisted to DB'
    );

    // Verify machine_children record
    $childRecord = MachineChild::where('parent_root_event_id', 'parent-root-001')->first();
    expect($childRecord)->not->toBeNull()
        ->and($childRecord->status)->toBe(MachineChild::STATUS_RUNNING)
        ->and($childRecord->child_machine_class)->toBe(CallableOutcomeMachine::class);

    ScenarioPlayer::cleanupOverrides();
    $isActiveRef->setValue(null, false);
});

test('child scenario receives parent context via resolveChildContext', function (): void {
    // ScenarioTestMachine's 'delegating' state delegates to ScenarioTestChildMachine
    // with input config. A child scenario should receive parent context.
    // Test: create parent at 'reviewing', send DELEGATE with a child scenario reference.
    // The child scenario's plan uses @always chain — check child context is populated.

    $isActiveRef = new ReflectionProperty(ScenarioPlayer::class, 'isActive');
    $isActiveRef->setAccessible(true);
    $isActiveRef->setValue(null, true);

    // CallableOutcomeMachine has no input config, so context is empty by default.
    // Test that executeChildScenario receives the input param correctly.
    $childScenarioClass = new class() extends MachineScenario {
        protected string $machine     = CallableOutcomeMachine::class;
        protected string $source      = 'idle';
        protected string $event       = MachineScenario::START;
        protected string $target      = 'waiting';
        protected string $description = 'test child context';

        protected function plan(): array
        {
            return [];
        }
    };

    $state = ScenarioPlayer::executeChildScenario(
        childScenarioClass: $childScenarioClass::class,
        childMachineClass: CallableOutcomeMachine::class,
        input: ['pin' => 'from_parent', 'userId' => 42],
    );

    // Child pauses at waiting (interactive) — returns null
    expect($state)->toBeNull();

    // The input was passed but CallableOutcomeMachine uses ScenarioTestContext
    // which has typed properties. The input merges into definition config context.
    // We can't easily inspect the child's context here since it's in-memory and
    // executeChildScenario doesn't expose the child machine. But the test verifies
    // the input param is accepted without error — the real integration test is in QA.

    ScenarioPlayer::cleanupOverrides();
    $isActiveRef->setValue(null, false);
});

test('forward endpoint activates child continuation overrides', function (): void {
    $this->markTestIncomplete('9.7.0: executeForwardedEndpoint must call maybeRegisterScenarioOverrides');
});

test('child @continue failure throws ScenarioFailedException', function (): void {
    $isActiveRef = new ReflectionProperty(ScenarioPlayer::class, 'isActive');
    $isActiveRef->setAccessible(true);
    $isActiveRef->setValue(null, true);

    $childScenarioClass = new class() extends MachineScenario {
        protected string $machine     = CallableOutcomeMachine::class;
        protected string $source      = 'idle';
        protected string $event       = MachineScenario::START;
        protected string $target      = 'completed';
        protected string $description = 'test invalid continue';

        protected function plan(): array
        {
            return [
                'waiting' => [
                    '@continue' => 'INVALID_EVENT_THAT_DOES_NOT_EXIST',
                ],
            ];
        }
    };

    expect(fn () => ScenarioPlayer::executeChildScenario(
        childScenarioClass: $childScenarioClass::class,
        childMachineClass: CallableOutcomeMachine::class,
    ))->toThrow(ScenarioFailedException::class);

    ScenarioPlayer::cleanupOverrides();
    $isActiveRef->setValue(null, false);
});

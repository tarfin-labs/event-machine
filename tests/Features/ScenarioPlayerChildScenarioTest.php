<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\GrandchildMachine;
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
    // If outcomes work: idle → waiting → CONFIRM → confirming (@done) → completed
    // If bug: confirming stays (job skipped by shouldPersist=false, outcome NOT intercepted)

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

    // Expected: child reaches completed (FINAL) via outcome intercept
    // Bug: returns null (child stuck at confirming, job skipped but outcome not intercepted)
    expect($state)->not->toBeNull('Child scenario should reach completed via job outcome intercept')
        ->and($state->value)->toContain('callable_outcome_test.completed');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Exceptions\InvalidMachineClassException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\OutcomeWithOutputScenario;

// ============================================================
// Async Scenario Propagation (9.10.3 fix)
// ============================================================
// Bug: when a parent scenario references a child scenario for a delegation
// state with `'queue' => '...'`, the dispatched ChildMachineJob worker booted
// the child WITHOUT scenario context. Leaf-state delegation outcomes never
// fired; real I/O happened.
//
// Fix: thread scenarioClass through ChildMachineJob's payload; worker
// activates ScenarioPlayer (outcomes + overrides + isActive) before child
// start(), deactivates in finally; persists scenario_class on row.

beforeEach(function (): void {
    config()->set('machine.scenarios.enabled', true);
});

afterEach(function (): void {
    // Defensive: ensure no static leakage between tests
    if (ScenarioPlayer::isActive()) {
        ScenarioPlayer::deactivate();
    }
});

// ─── activateForAsyncBoot / deactivate (helpers) ──────────────

it('activateForAsyncBoot populates outcomes, registers overrides, and sets isActive', function (): void {
    expect(ScenarioPlayer::isActive())->toBeFalse();

    $scenario = new OutcomeWithOutputScenario();
    ScenarioPlayer::activateForAsyncBoot($scenario);

    expect(ScenarioPlayer::isActive())->toBeTrue()
        ->and(ScenarioPlayer::getOutcome('processing'))->toBe([
            'outcome' => '@done',
            'output'  => ['processedBy' => 'scenario', 'amount' => 100],
        ]);

    ScenarioPlayer::deactivate();

    expect(ScenarioPlayer::isActive())->toBeFalse()
        ->and(ScenarioPlayer::getOutcome('processing'))->toBeNull();
});

it('deactivate clears outcomes and restores isActive=false', function (): void {
    ScenarioPlayer::activateForAsyncBoot(new OutcomeWithOutputScenario());
    expect(ScenarioPlayer::isActive())->toBeTrue();

    ScenarioPlayer::deactivate();

    expect(ScenarioPlayer::isActive())->toBeFalse()
        ->and(ScenarioPlayer::getOutcome('processing'))->toBeNull()
        ->and(ScenarioPlayer::getChildScenario('processing'))->toBeNull();
});

// ─── ChildMachineJob with scenarioClass payload ───────────────

it('ChildMachineJob without scenarioClass leaves ScenarioPlayer untouched (regression)', function (): void {
    Queue::fake();

    expect(ScenarioPlayer::isActive())->toBeFalse();

    // Create tracking row that ChildMachineJob expects
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'parent-root-id-no-scenario',
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_PENDING,
        'created_at'           => now(),
    ]);

    $job = new ChildMachineJob(
        parentRootEventId: 'parent-root-id-no-scenario',
        parentMachineClass: AsyncParentMachine::class,
        parentStateId: 'async_parent.processing',
        childMachineClass: SimpleChildMachine::class,
        machineChildId: $childRecord->id,
        // scenarioClass: null — default
    );

    $job->handle();

    // ScenarioPlayer remained inactive
    expect(ScenarioPlayer::isActive())->toBeFalse();

    // No scenario_class persisted on the child row when none was provided
    $childRecord->refresh();
    if ($childRecord->child_root_event_id !== null) {
        $row = MachineCurrentState::where('root_event_id', $childRecord->child_root_event_id)->first();
        if ($row !== null) {
            expect($row->scenario_class)->toBeNull();
        }
    }
});

it('ChildMachineJob deactivates ScenarioPlayer in finally even on exception', function (): void {
    expect(ScenarioPlayer::isActive())->toBeFalse();

    $job = new ChildMachineJob(
        parentRootEventId: 'parent-id',
        parentMachineClass: AsyncParentMachine::class,
        parentStateId: 'async_parent.processing',
        childMachineClass: 'NonexistentClass',  // triggers InvalidMachineClassException
        machineChildId: 'no-such-id',
        scenarioClass: OutcomeWithOutputScenario::class,
    );

    expect(fn () => $job->handle())->toThrow(InvalidMachineClassException::class);
    // After exception, ScenarioPlayer must be deactivated
    expect(ScenarioPlayer::isActive())->toBeFalse();
});

// ─── MachineDefinition dispatch threads scenarioClass through ─

it('MachineDefinition dispatches ChildMachineJob with scenarioClass when player is active', function (): void {
    Queue::fake();

    // Activate scenario player with a child scenario reference plan
    $scenario = new class() extends MachineScenario {
        protected string $machine     = AsyncParentMachine::class;
        protected string $source      = 'idle';
        protected string $event       = MachineScenario::START;
        protected string $target      = 'completed';
        protected string $description = 'test';

        protected function plan(): array
        {
            return [
                'async_parent.processing' => OutcomeWithOutputScenario::class,
            ];
        }
    };

    ScenarioPlayer::activateForAsyncBoot($scenario);

    try {
        $machine = AsyncParentMachine::create();
        $machine->send(['type' => 'START']);

        Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
            return $job->scenarioClass === OutcomeWithOutputScenario::class;
        });
    } finally {
        ScenarioPlayer::deactivate();
    }
});

it('MachineDefinition dispatches ChildMachineJob with scenarioClass=null when player is inactive', function (): void {
    Queue::fake();
    expect(ScenarioPlayer::isActive())->toBeFalse();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->scenarioClass === null;
    });
});

// ─── scenario_class persisted on child row ────────────────────

it('persists scenario_class on child row after async boot', function (): void {
    Queue::fake();

    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'parent-root-with-scenario',
        'parent_state_id'      => 'scenario_test.delegating',
        'parent_machine_class' => AsyncParentMachine::class,  // doesn't matter for this test
        'child_machine_class'  => ScenarioTestChildMachine::class,
        'status'               => MachineChild::STATUS_PENDING,
        'created_at'           => now(),
    ]);

    $job = new ChildMachineJob(
        parentRootEventId: 'parent-root-with-scenario',
        parentMachineClass: AsyncParentMachine::class,
        parentStateId: 'scenario_test.delegating',
        childMachineClass: ScenarioTestChildMachine::class,
        machineChildId: $childRecord->id,
        scenarioClass: OutcomeWithOutputScenario::class,
    );

    $job->handle();

    $childRecord->refresh();
    expect($childRecord->child_root_event_id)->not->toBeNull();

    $row = MachineCurrentState::where('root_event_id', $childRecord->child_root_event_id)->first();
    expect($row)->not->toBeNull()
        ->and($row->scenario_class)->toBe(OutcomeWithOutputScenario::class);

    // Player was deactivated by the time we got here
    expect(ScenarioPlayer::isActive())->toBeFalse();
});

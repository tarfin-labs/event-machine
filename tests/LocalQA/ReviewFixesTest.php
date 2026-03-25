<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryWithMaxMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ForwardableChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncTimeoutParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  P0: @every timer atomic dedup — no double-fire under real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: every timer does not double-fire on rapid successive sweeps', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past interval (30 days)
    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    // First sweep
    Artisan::call('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $firstFired = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
        if (!$fire || $fire->fire_count < 1) {
            return false;
        }

        // Also wait for the timer job to actually process (update context)
        $restored = EveryTimerMachine::create(state: $rootEventId);

        return $restored->state->context->get('billing_count') >= 1;
    }, timeoutSeconds: 45, description: 'every timer: waiting for fire_count>=1 AND billing_count>=1');

    expect($firstFired)->toBeTrue('First every-timer fire not processed');

    // Second sweep immediately — fire record should block (last_fired_at is recent)
    Artisan::call('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    // Negative assertion: verify second sweep does NOT fire.
    // sleep required — cannot waitFor absence.
    sleep(1);

    // Verify: fire_count should be exactly 1 (not 2)
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire->fire_count)->toBe(1, 'Every timer double-fired on rapid successive sweeps');

    // Verify: billing_count in machine context should be 1
    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billing_count'))->toBe(1);
});

it('LocalQA: every timer fire record exists before job is processed', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    // Run sweep — fire record should be written BEFORE jobs are dispatched
    Artisan::call('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    // Check immediately: fire record should already exist (written before dispatch)
    $fireExists = MachineTimerFire::where('root_event_id', $rootEventId)->exists();
    expect($fireExists)->toBeTrue('Fire record was not created before job dispatch');

    // Now wait for the actual job to process
    $processed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = EveryTimerMachine::create(state: $rootEventId);

        return $restored->state->context->get('billing_count') >= 1;
    }, timeoutSeconds: 60);

    expect($processed)->toBeTrue('Every timer job not processed by Horizon');
});

// ═══════════════════════════════════════════════════════════════
//  P0: @every max/then transaction atomicity
// ═══════════════════════════════════════════════════════════════

it('LocalQA: every max/then fires then-event and marks exhausted atomically', function (): void {
    $machine = EveryWithMaxMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run 3 retry cycles
    for ($i = 1; $i <= 3; $i++) {
        DB::table('machine_current_states')
            ->where('root_event_id', $rootEventId)
            ->update(['state_entered_at' => now()->subHours(7)]);

        MachineTimerFire::where('root_event_id', $rootEventId)
            ->update(['last_fired_at' => now()->subHours(7)]);

        Artisan::call('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

        $fired = LocalQATestCase::waitFor(function () use ($rootEventId, $i) {
            $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
            if (!$fire || $fire->fire_count < $i) {
                return false;
            }

            // Wait for Horizon to process the timer job (context updated)
            $restored = EveryWithMaxMachine::create(state: $rootEventId);

            return $restored->state->context->get('retry_count') >= $i;
        }, timeoutSeconds: 60, description: "every max/then: cycle {$i} fire_count+retry_count");

        expect($fired)->toBeTrue("Retry cycle {$i} not processed");
    }

    // Cycle 4: max reached → MAX_RETRIES (then event) → state = failed
    MachineTimerFire::where('root_event_id', $rootEventId)
        ->update(['last_fired_at' => now()->subHours(7)]);

    Artisan::call('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

    $exhausted = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60);

    expect($exhausted)->toBeTrue('Max/then did not transition to failed');

    // Verify atomicity: status MUST be exhausted
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire->status)->toBe(MachineTimerFire::STATUS_EXHAUSTED, 'Fire record not marked exhausted');
    expect($fire->fire_count)->toBe(3, 'Fire count should be 3 (max)');

    // Cycle 5: nothing happens (exhausted, no more fires)
    // Negative assertion: sleep required — cannot waitFor absence.
    Artisan::call('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);
    sleep(1);

    $stillFailed = EveryWithMaxMachine::create(state: $rootEventId);
    expect($stillFailed->state->currentStateDefinition->id)->toBe('every_max.failed');
});

// ═══════════════════════════════════════════════════════════════
//  P1: Forward event routing — full async pipeline
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward event routing delivers APPROVE to running child', function (): void {
    // 1. Create parent, enter processing state (async child dispatched)
    $parent = ForwardParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $parentRootEventId = $parent->state->history->first()->root_event_id;

    // 2. Wait for child to be running (ChildMachineJob processed by Horizon)
    $childRunning = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $child = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue('Child machine did not reach running status');

    $childRecord      = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();
    $childRootEventId = $childRecord->child_root_event_id;

    // Verify child is in idle state
    $child = ForwardableChildMachine::create(state: $childRootEventId);
    expect($child->state->currentStateDefinition->id)->toBe('forwardable_child.idle');

    // 3. Restore parent and forward APPROVE
    $restoredParent = ForwardParentMachine::create(state: $parentRootEventId);
    $restoredParent->send(['type' => 'APPROVE']);

    // 4. Wait for child to transition to approved (forward delivered + processed)
    $childApproved = LocalQATestCase::waitFor(function () use ($childRootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $childRootEventId)->first();

        return $cs && str_contains($cs->state_id, 'approved');
    }, timeoutSeconds: 60);

    expect($childApproved)->toBeTrue('Forward APPROVE was not delivered to child');

    // 5. Child reached final → parent should auto-complete via @done
    $parentCompleted = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $parentRootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($parentCompleted)->toBeTrue('Parent did not transition to completed after child approval');
});

it('LocalQA: forward event routing renames PARENT_UPDATE to CHILD_UPDATE', function (): void {
    $parent = ForwardParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $parentRootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to be running
    $childRunning = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $child = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue('Child machine did not reach running status');

    $childRecord      = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();
    $childRootEventId = $childRecord->child_root_event_id;

    // Forward PARENT_UPDATE → child receives CHILD_UPDATE
    $restoredParent = ForwardParentMachine::create(state: $parentRootEventId);
    $restoredParent->send(['type' => 'PARENT_UPDATE']);

    // Wait for child to transition to updated (CHILD_UPDATE received)
    $childUpdated = LocalQATestCase::waitFor(function () use ($childRootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $childRootEventId)->first();

        return $cs && str_contains($cs->state_id, 'updated');
    }, timeoutSeconds: 60);

    expect($childUpdated)->toBeTrue('Renamed forward PARENT_UPDATE→CHILD_UPDATE was not delivered');

    // Verify child context was updated by the action
    $child = ForwardableChildMachine::create(state: $childRootEventId);
    expect($child->state->context->get('updated_value'))->toBe('received');
});

// ═══════════════════════════════════════════════════════════════
//  P2: Definition clone — no context bleed between children
// ═══════════════════════════════════════════════════════════════

it('LocalQA: concurrent child machines do not share mutated definition context', function (): void {
    // Create two parents that will each delegate to a child
    $parent1 = ForwardParentMachine::create();
    $parent1->send(['type' => 'START']);
    $parent1->persist();
    $parent1Root = $parent1->state->history->first()->root_event_id;

    $parent2 = ForwardParentMachine::create();
    $parent2->send(['type' => 'START']);
    $parent2->persist();
    $parent2Root = $parent2->state->history->first()->root_event_id;

    // Wait for both children to be running
    $bothRunning = LocalQATestCase::waitFor(function () use ($parent1Root, $parent2Root) {
        $child1 = MachineChild::where('parent_root_event_id', $parent1Root)->first();
        $child2 = MachineChild::where('parent_root_event_id', $parent2Root)->first();

        return $child1 && $child1->status === MachineChild::STATUS_RUNNING
            && $child2 && $child2->status === MachineChild::STATUS_RUNNING;
    }, timeoutSeconds: 60);

    expect($bothRunning)->toBeTrue('Both children did not reach running status');

    // Verify each child has independent context (no bleed from shared definition)
    $child1Record = MachineChild::where('parent_root_event_id', $parent1Root)->first();
    $child2Record = MachineChild::where('parent_root_event_id', $parent2Root)->first();

    $child1 = ForwardableChildMachine::create(state: $child1Record->child_root_event_id);
    $child2 = ForwardableChildMachine::create(state: $child2Record->child_root_event_id);

    // Both should be in idle with their own default context
    expect($child1->state->currentStateDefinition->id)->toBe('forwardable_child.idle')
        ->and($child2->state->currentStateDefinition->id)->toBe('forwardable_child.idle')
        ->and($child1->state->context->get('updated_value'))->toBeNull()
        ->and($child2->state->context->get('updated_value'))->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
//  P2: CompletionJob retry under lock contention
// ═══════════════════════════════════════════════════════════════

it('LocalQA: completion job succeeds with retry config (lock released)', function (): void {
    // Use APPROVE forward which sends child to final → triggers completion automatically
    $parent = ForwardParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $parentRootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to be running
    $childRunning = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $child = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue();

    // Forward APPROVE → child reaches final state → ChildMachineCompletionJob auto-dispatched
    $restoredParent = ForwardParentMachine::create(state: $parentRootEventId);
    $restoredParent->send(['type' => 'APPROVE']);

    // Wait for parent to complete via @done
    $parentCompleted = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $parentRootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($parentCompleted)->toBeTrue('Parent did not complete via @done after child completion');

    // Verify no stale locks
    $lockCount = DB::table('machine_locks')->count();
    expect($lockCount)->toBe(0, 'Stale locks found after completion');
});

// ═══════════════════════════════════════════════════════════════
//  P2: TimeoutJob real race with child completion
// ═══════════════════════════════════════════════════════════════

it('LocalQA: timeout job is no-op when child completes before timeout', function (): void {
    // AsyncTimeoutParentMachine has @timeout configured
    $parent = AsyncTimeoutParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $parentRootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to reach running
    $childRunning = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $child = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue('Child did not reach running');

    $childRecord = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();

    // Complete child quickly (before timeout fires)
    $childRecord->markCompleted();

    // Negative assertion: verify timeout does NOT override completed.
    // sleep required — cannot waitFor absence.
    sleep(1);

    // Child should still be completed (not timed_out)
    $childRecord->refresh();
    expect($childRecord->status)->toBe(MachineChild::STATUS_COMPLETED, 'Timeout job incorrectly overrode completed child');
});

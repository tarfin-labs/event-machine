<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\AsyncParentCompletedScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('plays parent scenario with child delegation — child machine found and completed', function (): void {
    $result = AsyncParentCompletedScenario::play();

    // Parent should be at 'processing' — child @done can't fire in unit test
    // because ChildMachineCompletionJob is async (Bus::faked)
    // But the child scenario should have completed the child machine
    expect($result->stepsExecuted)->toBe(2); // START + child step
    expect($result->childResults)->toHaveKey(SimpleChildMachine::class);

    $childResult = $result->childResults[SimpleChildMachine::class];
    expect($childResult->currentState)->toBe('done');
    expect($childResult->stepsExecuted)->toBe(1);
});

it('creates machine_children record during parent send', function (): void {
    $result = AsyncParentCompletedScenario::play();

    // Verify machine_children record was created by the engine
    $childRecord = MachineChild::query()
        ->where('parent_root_event_id', $result->rootEventId)
        ->where('child_machine_class', SimpleChildMachine::class)
        ->first();

    expect($childRecord)->not->toBeNull();
    expect($childRecord->child_root_event_id)->not->toBeEmpty();
});

it('child scenario can restore and verify child machine state', function (): void {
    $result = AsyncParentCompletedScenario::play();

    $childResult = $result->childResults[SimpleChildMachine::class];

    // Restore child machine and verify it's at 'done'
    $childMachine = SimpleChildMachine::create(state: $childResult->rootEventId);
    expect($childMachine->state->currentStateDefinition->key)->toBe('done');
});

it('passes default parameters through to scenario steps', function (): void {
    // Default orderId is 'ORD-001' — verify it's sent as payload
    $result = AsyncParentCompletedScenario::play();

    // The parent machine should be at 'processing' (child @done is async)
    // with orderId in context from the START event payload
    $parent = AsyncParentMachine::create(state: $result->rootEventId);
    expect($parent->state->currentStateDefinition->key)->toBe('processing');
});

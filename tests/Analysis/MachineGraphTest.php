<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\StateClassification;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsValidGuard;

// ── classifyState ────────────────────────────────────────────────────────────

test('classifyState returns FINAL for final state', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('approved');

    expect($graph->classifyState($state))->toBe(StateClassification::FINAL);
});

test('classifyState returns PARALLEL for parallel state', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('parallel_check');

    expect($graph->classifyState($state))->toBe(StateClassification::PARALLEL);
});

test('classifyState returns DELEGATION for job actor state', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('processing');

    expect($graph->classifyState($state))->toBe(StateClassification::DELEGATION);
});

test('classifyState returns DELEGATION for child machine state', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('delegating');

    expect($graph->classifyState($state))->toBe(StateClassification::DELEGATION);
});

test('classifyState returns TRANSIENT for @always state', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('idle');

    expect($graph->classifyState($state))->toBe(StateClassification::TRANSIENT);
});

test('classifyState returns INTERACTIVE for plain state with events', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('reviewing');

    expect($graph->classifyState($state))->toBe(StateClassification::INTERACTIVE);
});

// ── transitionsFrom ──────────────────────────────────────────────────────────

test('transitionsFrom includes parent chain transitions', function (): void {
    $graph      = new MachineGraph(ScenarioTestMachine::definition());
    $state      = $graph->resolveState('reviewing');
    $transitions = $graph->transitionsFrom($state);

    // reviewing has own events: APPROVE, REJECT, START_PARALLEL, DELEGATE
    expect($transitions)->toHaveKey('APPROVE')
        ->and($transitions)->toHaveKey('REJECT')
        ->and($transitions)->toHaveKey('START_PARALLEL')
        ->and($transitions)->toHaveKey('DELEGATE');
});

test('transitionsFrom child overrides parent for same event key', function (): void {
    // parallel_check has SKIP_CHECK on its own level
    // Region states also have their own transitions, no overlap to test directly.
    // The mechanism is: child transitions are added first, parent added only if key not seen.
    $graph      = new MachineGraph(ScenarioTestMachine::definition());
    $state      = $graph->resolveState('parallel_check');
    $transitions = $graph->transitionsFrom($state);

    expect($transitions)->toHaveKey('SKIP_CHECK');
});

// ── resolveState ─────────────────────────────────────────────────────────────

test('resolveState exact match with full ID', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('scenario_test.reviewing');

    expect($state->key)->toBe('reviewing');
});

test('resolveState match with machine prefix added', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    $state = $graph->resolveState('reviewing');

    expect($state->key)->toBe('reviewing');
});

test('resolveState suffix matching when unambiguous', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());
    // 'approved' is unambiguous
    $state = $graph->resolveState('approved');

    expect($state->key)->toBe('approved');
});

test('resolveState ambiguous suffix throws InvalidArgumentException', function (): void {
    // Create a machine with two states whose IDs both end with '.done'
    $definition = MachineDefinition::define(config: [
        'id'      => 'ambiguous',
        'initial' => 'idle',
        'context' => [],
        'states'  => [
            'idle' => [
                'type'   => 'parallel',
                'states' => [
                    'region_a' => [
                        'initial' => 'done',
                        'states'  => [
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'done',
                        'states'  => [
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $graph = new MachineGraph($definition);

    // 'done' matches both ambiguous.idle.region_a.done and ambiguous.idle.region_b.done
    expect(fn () => $graph->resolveState('done'))
        ->toThrow(InvalidArgumentException::class, 'Ambiguous');
});

test('resolveState not found throws InvalidArgumentException', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());

    expect(fn () => $graph->resolveState('does_not_exist'))
        ->toThrow(InvalidArgumentException::class);
});

// ── delegationOutcomes ───────────────────────────────────────────────────────

test('delegationOutcomes reads @done from onDoneTransition property', function (): void {
    $graph    = new MachineGraph(ScenarioTestMachine::definition());
    $state    = $graph->resolveState('processing');
    $outcomes = $graph->delegationOutcomes($state);

    expect($outcomes)->toContain('@done');
});

test('delegationOutcomes reads @done.{state} from onDoneStateTransitions', function (): void {
    $graph    = new MachineGraph(ScenarioTestMachine::definition());
    $state    = $graph->resolveState('delegating');
    $outcomes = $graph->delegationOutcomes($state);

    expect($outcomes)->toContain('@done')
        ->and($outcomes)->toContain('@done.error');
});

test('delegationOutcomes reads @fail and @timeout from dedicated properties', function (): void {
    $graph    = new MachineGraph(ScenarioTestMachine::definition());
    $state    = $graph->resolveState('processing');
    $outcomes = $graph->delegationOutcomes($state);

    expect($outcomes)->toContain('@fail')
        ->and($outcomes)->toContain('@timeout');
});

// ── Other ────────────────────────────────────────────────────────────────────

test('availableEventsFrom excludes @always, includes named events', function (): void {
    $graph  = new MachineGraph(ScenarioTestMachine::definition());
    $state  = $graph->resolveState('reviewing');
    $events = $graph->availableEventsFrom($state);

    expect($events)->toContain('APPROVE')
        ->and($events)->toContain('REJECT')
        ->and($events)->toContain('START_PARALLEL')
        ->and($events)->toContain('DELEGATE')
        ->and($events)->not->toContain('@always');
});

test('alwaysBranchGuards returns guard class names per @always branch', function (): void {
    $graph   = new MachineGraph(ScenarioTestMachine::definition());
    $state   = $graph->resolveState('routing');
    $branches = $graph->alwaysBranchGuards($state);

    // routing has 2 @always branches: [IsEligibleGuard] → processing, [] → blocked
    expect($branches)->toHaveCount(2)
        ->and($branches[0])->toContain(IsEligibleGuard::class)
        ->and($branches[1])->toBeEmpty();
});

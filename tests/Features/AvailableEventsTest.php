<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;

// ============================================================
// Parent On-Events
// ============================================================

test('it returns parent on-events for simple atomic state', function (): void {
    $test = TestMachine::define([
        'id'      => 'simple',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'EVENT_A' => 'done',
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $events = $test->state()->availableEvents();

    expect($events)->toBe([
        ['type' => 'EVENT_A', 'source' => 'parent'],
    ]);
});

test('it returns multiple parent on-events', function (): void {
    $test = TestMachine::define([
        'id'      => 'multi',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'GO'     => 'active',
                    'SKIP'   => 'done',
                    'CANCEL' => 'done',
                ],
            ],
            'active' => ['on' => ['FINISH' => 'done']],
            'done'   => ['type' => 'final'],
        ],
    ]);

    $events = $test->state()->availableEvents();
    $types  = array_column($events, 'type');

    expect($types)->toBe(['GO', 'SKIP', 'CANCEL']);
});

test('it returns events with source parent annotation', function (): void {
    $test = TestMachine::define([
        'id'      => 'src_check',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'SUBMIT' => 'processing',
                    'CANCEL' => 'cancelled',
                ],
            ],
            'processing' => ['type' => 'final'],
            'cancelled'  => ['type' => 'final'],
        ],
    ]);

    $events = $test->state()->availableEvents();

    foreach ($events as $event) {
        expect($event)->toHaveKey('source')
            ->and($event['source'])->toBe('parent');
    }
});

// ============================================================
// Internal Event Exclusions
// ============================================================

test('it excludes @always from available events', function (): void {
    // Define machine with @always on a non-initial state that has a guard
    // preventing immediate transition, so we can observe the state
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'always_test',
            'initial' => 'idle',
            'context' => ['ready' => false],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'checking'],
                ],
                'checking' => [
                    'on' => [
                        'RETRY'   => 'idle',
                        '@always' => [
                            'target' => 'done',
                            'guards' => 'isReadyGuard',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isReadyGuard' => fn (ContextManager $ctx): bool => $ctx->get('ready') === true,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'GO'], state: $state);

    // We're in 'checking' state (guard failed, @always didn't fire)
    expect($state->currentStateDefinition->id)->toBe('always_test.checking');

    $events = $state->availableEvents();
    $types  = array_column($events, 'type');

    expect($types)->not->toContain('@always')
        ->and($types)->toContain('RETRY');
});

test('it excludes @done from available events', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'done_test',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'on'    => ['CANCEL' => 'cancelled'],
                '@done' => 'completed',
            ],
            'completed' => ['type' => 'final'],
            'cancelled' => ['type' => 'final'],
        ],
    ]);

    $state  = $definition->getInitialState();
    $events = $state->availableEvents();
    $types  = array_column($events, 'type');

    expect($types)->not->toContain('@done')
        ->and($types)->toContain('CANCEL');
});

test('it excludes @fail from available events', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'fail_test',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'on'    => ['RETRY' => 'processing'],
                '@fail' => 'failed',
            ],
            'failed' => ['type' => 'final'],
        ],
    ]);

    $state  = $definition->getInitialState();
    $events = $state->availableEvents();
    $types  = array_column($events, 'type');

    expect($types)->not->toContain('@fail')
        ->and($types)->toContain('RETRY');
});

test('it excludes @timeout from available events', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'timeout_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on'       => ['COMPLETE' => 'done'],
                '@timeout' => 'timed_out',
            ],
            'done'      => ['type' => 'final'],
            'timed_out' => ['type' => 'final'],
        ],
    ]);

    $state  = $definition->getInitialState();
    $events = $state->availableEvents();
    $types  = array_column($events, 'type');

    expect($types)->not->toContain('@timeout')
        ->and($types)->toContain('COMPLETE');
});

test('it includes timer events since they are user-sendable event types', function (): void {
    $test = TestMachine::define([
        'id'      => 'timer_visible',
        'initial' => 'awaiting_payment',
        'states'  => [
            'awaiting_payment' => [
                'on' => [
                    'PAY'           => 'processing',
                    'ORDER_EXPIRED' => ['target' => 'cancelled', 'after' => Timer::days(7)],
                ],
            ],
            'processing' => ['type' => 'final'],
            'cancelled'  => ['type' => 'final'],
        ],
    ]);

    $events = $test->state()->availableEvents();
    $types  = array_column($events, 'type');

    // Timer events use regular event names; they are user-sendable
    expect($types)->toContain('PAY')
        ->and($types)->toContain('ORDER_EXPIRED');
});

// ============================================================
// Final State
// ============================================================

test('it returns empty array for final state', function (): void {
    $test = TestMachine::define([
        'id'      => 'final_check',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $test->send('GO');

    $events = $test->state()->availableEvents();

    expect($events)->toBe([]);
});

test('it returns empty array when current state definition is null', function (): void {
    $state = State::forTesting(
        context: [],
        currentStateDefinition: null,
    );

    expect($state->availableEvents())->toBe([]);
});

// ============================================================
// Events After Transition
// ============================================================

test('it returns available events for new state after transition', function (): void {
    $test = TestMachine::define([
        'id'      => 'transition_check',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['START' => 'active'],
            ],
            'active' => [
                'on' => [
                    'PAUSE'  => 'paused',
                    'FINISH' => 'done',
                ],
            ],
            'paused' => [
                'on' => ['RESUME' => 'active'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    // In idle state
    expect(array_column($test->state()->availableEvents(), 'type'))->toBe(['START']);

    // After transition to active
    $test->send('START');
    expect(array_column($test->state()->availableEvents(), 'type'))->toBe(['PAUSE', 'FINISH']);

    // After transition to paused
    $test->send('PAUSE');
    expect(array_column($test->state()->availableEvents(), 'type'))->toBe(['RESUME']);
});

// ============================================================
// Forward Events
// ============================================================

test('it includes forward events when parent is in delegating state with running child', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    // Parent should be in processing state
    expect($parent->state->currentStateDefinition->id)->toBe('forward_endpoint_parent.processing');

    // The async dispatch created a MachineChild record with status=pending
    // but child_root_event_id is null (job hasn't run). Simulate child startup.
    $childRecord = MachineChild::first();
    expect($childRecord)->not->toBeNull();

    // Create a child machine instance and persist to get MachineCurrentState
    $childMachine = ForwardChildEndpointMachine::create();
    $childRootId  = $childMachine->state->history->first()->root_event_id;
    $childMachine->persist();

    // Update MachineChild to link the child and mark it running
    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    // Now check availableEvents on the parent
    $events  = $parent->state->availableEvents();
    $types   = array_column($events, 'type');
    $sources = array_column($events, 'source');

    // Parent on-event: CANCEL (source: parent)
    expect($types)->toContain('CANCEL');

    // Forward event: PROVIDE_CARD (child accepts it in awaiting_card state)
    expect($types)->toContain('PROVIDE_CARD');

    // CONFIRM_PAYMENT should NOT be available because child is in
    // awaiting_card, which does NOT have CONFIRM_PAYMENT transition
    expect($types)->not->toContain('CONFIRM_PAYMENT');
});

test('forward events have source forward annotation', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    $childRecord = MachineChild::first();

    $childMachine = ForwardChildEndpointMachine::create();
    $childRootId  = $childMachine->state->history->first()->root_event_id;
    $childMachine->persist();

    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    $events = $parent->state->availableEvents();

    $forwardEvents = array_filter($events, fn (array $e): bool => $e['source'] === 'forward');

    expect($forwardEvents)->not->toBeEmpty();

    foreach ($forwardEvents as $event) {
        expect($event['source'])->toBe('forward');
    }
});

test('it excludes forward events when no running child exists', function (): void {
    $test = TestMachine::define([
        'id'      => 'no_child',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['START' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    // idle state has no machine invoke, so no forward events
    $events  = $test->state()->availableEvents();
    $sources = array_column($events, 'source');

    expect($sources)->not->toContain('forward');
});

test('it excludes forward events when parent is not in delegating state', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();

    // Parent is in idle state, not processing (no child invoked)
    expect($parent->state->currentStateDefinition->id)->toBe('forward_endpoint_parent.idle');

    $events  = $parent->state->availableEvents();
    $sources = array_column($events, 'source');

    expect($sources)->not->toContain('forward')
        ->and(array_column($events, 'type'))->toBe(['START']);
});

test('it returns both forward and parent on-events together', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    $childRecord = MachineChild::first();

    // Create child and advance to awaiting_confirmation so both PROVIDE_CARD
    // and CONFIRM_PAYMENT are visible
    $childMachine = ForwardChildEndpointMachine::create();
    $childMachine->send(['type' => 'PROVIDE_CARD', 'payload' => ['card_number' => '4111111111111111']]);
    $childRootId = $childMachine->state->history->first()->root_event_id;

    // Child is now in awaiting_confirmation
    expect($childMachine->state->currentStateDefinition->id)
        ->toBe('forward_endpoint_child.awaiting_confirmation');

    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    // Update MachineCurrentState to reflect the child's actual state
    MachineCurrentState::where('root_event_id', $childRootId)
        ->update(['state_id' => 'forward_endpoint_child.awaiting_confirmation']);

    $events = $parent->state->availableEvents();
    $types  = array_column($events, 'type');

    // Parent on-event
    expect($types)->toContain('CANCEL');

    // Forward events — child in awaiting_confirmation accepts CONFIRM_PAYMENT and ABORT
    // PROVIDE_CARD is NOT accepted by awaiting_confirmation
    expect($types)->toContain('CONFIRM_PAYMENT');
    expect($types)->not->toContain('PROVIDE_CARD');

    // Check mixed sources
    $parentEvents  = array_filter($events, fn (array $e): bool => $e['source'] === 'parent');
    $forwardEvents = array_filter($events, fn (array $e): bool => $e['source'] === 'forward');

    expect($parentEvents)->not->toBeEmpty()
        ->and($forwardEvents)->not->toBeEmpty();
});

test('forward events reflect child current state not initial state', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    $childRecord = MachineChild::first();

    // Create child machine and advance past awaiting_card
    $childMachine = ForwardChildEndpointMachine::create();
    $childMachine->send(['type' => 'PROVIDE_CARD', 'payload' => ['card_number' => '4242424242424242']]);
    $childRootId = $childMachine->state->history->first()->root_event_id;

    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    MachineCurrentState::where('root_event_id', $childRootId)
        ->update(['state_id' => 'forward_endpoint_child.awaiting_confirmation']);

    $events = $parent->state->availableEvents();
    $types  = array_column($events, 'type');

    // awaiting_confirmation has CONFIRM_PAYMENT and ABORT, but NOT PROVIDE_CARD
    expect($types)->toContain('CONFIRM_PAYMENT')
        ->and($types)->not->toContain('PROVIDE_CARD');
});

// ============================================================
// Parallel State Events
// ============================================================

test('it returns events from all active regions in parallel state', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'par',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'region_a' => [
                        'initial' => 'a_idle',
                        'states'  => [
                            'a_idle' => [
                                'on' => ['EVENT_A' => 'a_done'],
                            ],
                            'a_done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'b_idle',
                        'states'  => [
                            'b_idle' => [
                                'on' => ['EVENT_B' => 'b_done'],
                            ],
                            'b_done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state  = $definition->getInitialState();
    $events = $state->availableEvents();
    $types  = array_column($events, 'type');

    expect($types)->toContain('EVENT_A')
        ->and($types)->toContain('EVENT_B')
        ->and($events)->toHaveCount(2);
});

test('parallel state events include region annotation', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'par_region',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'billing' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => [
                                'on' => ['PAY' => 'paid'],
                            ],
                            'paid' => ['type' => 'final'],
                        ],
                    ],
                    'shipping' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => [
                                'on' => ['SHIP' => 'shipped'],
                            ],
                            'shipped' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state  = $definition->getInitialState();
    $events = $state->availableEvents();

    $payEvent  = collect($events)->firstWhere('type', 'PAY');
    $shipEvent = collect($events)->firstWhere('type', 'SHIP');

    expect($payEvent)->not->toBeNull()
        ->and($payEvent['region'])->toBe('billing')
        ->and($shipEvent)->not->toBeNull()
        ->and($shipEvent['region'])->toBe('shipping');
});

test('parallel state events exclude internal events from all regions', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'par_internal',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'region_a' => [
                        'initial' => 'a1',
                        'states'  => [
                            'a1' => [
                                'on' => [
                                    'MOVE_A'  => 'a2',
                                    '@always' => 'a2',
                                ],
                            ],
                            'a2' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'b1',
                        'states'  => [
                            'b1' => [
                                'on' => ['MOVE_B' => 'b2'],
                            ],
                            'b2' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state  = $definition->getInitialState();
    $events = $state->availableEvents();
    $types  = array_column($events, 'type');

    expect($types)->not->toContain('@always')
        ->and($types)->toContain('MOVE_A')
        ->and($types)->toContain('MOVE_B');
});

// ============================================================
// Serialization
// ============================================================

test('State toArray includes available_events', function (): void {
    $test = TestMachine::define([
        'id'      => 'serial',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $array = $test->state()->toArray();

    expect($array)->toHaveKey('available_events')
        ->and($array['available_events'])->toBe([
            ['type' => 'GO', 'source' => 'parent'],
        ]);
});

test('State toArray available_events is empty for final state', function (): void {
    $test = TestMachine::define([
        'id'      => 'serial_final',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $test->send('GO');

    $array = $test->state()->toArray();

    expect($array['available_events'])->toBe([]);
});

test('Machine availableEvents proxies to state', function (): void {
    $test = TestMachine::define([
        'id'      => 'proxy',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['SUBMIT' => 'submitted'],
            ],
            'submitted' => ['type' => 'final'],
        ],
    ]);

    $machine = $test->machine();

    expect($machine->availableEvents())
        ->toBe($machine->state->availableEvents())
        ->toBe([['type' => 'SUBMIT', 'source' => 'parent']]);
});

// ============================================================
// TestMachine Assertions
// ============================================================

test('TestMachine assertAvailableEvent passes for existing event', function (): void {
    $test = TestMachine::define([
        'id'      => 'assert_avail',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    // Should not throw, and returns self for chaining
    $result = $test->assertAvailableEvent('GO');

    expect($result)->toBe($test);
});

test('TestMachine assertNotAvailableEvent passes for missing event', function (): void {
    $test = TestMachine::define([
        'id'      => 'assert_not_avail',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    // Should not throw, and returns self for chaining
    $result = $test->assertNotAvailableEvent('NONEXISTENT');

    expect($result)->toBe($test);
});

test('TestMachine assertAvailableEvents passes for exact match', function (): void {
    $test = TestMachine::define([
        'id'      => 'assert_exact',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'GO'     => 'active',
                    'CANCEL' => 'done',
                ],
            ],
            'active' => ['type' => 'final'],
            'done'   => ['type' => 'final'],
        ],
    ]);

    // Order-independent, returns self for chaining
    $result = $test->assertAvailableEvents(['CANCEL', 'GO']);

    expect($result)->toBe($test);
});

test('TestMachine assertNoAvailableEvents passes for final state', function (): void {
    $test = TestMachine::define([
        'id'      => 'assert_none',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $test->send('GO');
    $result = $test->assertNoAvailableEvents();

    expect($result)->toBe($test);
});

test('State jsonSerialize includes available_events', function (): void {
    $test = TestMachine::define([
        'id'      => 'json_serial',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['ACTIVATE' => 'active'],
            ],
            'active' => ['type' => 'final'],
        ],
    ]);

    $json = $test->state()->jsonSerialize();

    expect($json)->toHaveKey('available_events')
        ->and($json['available_events'])->toBe([
            ['type' => 'ACTIVATE', 'source' => 'parent'],
        ]);
});

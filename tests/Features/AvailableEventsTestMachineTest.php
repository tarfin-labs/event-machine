<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;

// ============================================================
// assertAvailableEvent()
// ============================================================

test('assertAvailableEvent passes when event is in available events', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'ae_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $result = $tm->assertAvailableEvent('GO');

    expect($result)->toBe($tm);
});

test('assertAvailableEvent fails when event is not in available events', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'ae_fail_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    expect(fn () => $tm->assertAvailableEvent('NONEXISTENT'))
        ->toThrow(AssertionFailedError::class);
});

// ============================================================
// assertNotAvailableEvent()
// ============================================================

test('assertNotAvailableEvent passes when event is not available', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'nae_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $result = $tm->assertNotAvailableEvent('NONEXISTENT');

    expect($result)->toBe($tm);
});

test('assertNotAvailableEvent fails when event is available', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'nae_fail_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    expect(fn () => $tm->assertNotAvailableEvent('GO'))
        ->toThrow(AssertionFailedError::class);
});

// ============================================================
// assertAvailableEvents()
// ============================================================

test('assertAvailableEvents passes for exact set (order-independent)', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'aes_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO'   => 'active',
                        'STOP' => 'done',
                    ],
                ],
                'active' => ['type' => 'final'],
                'done'   => ['type' => 'final'],
            ],
        ],
    );

    // Pass in reverse order to verify order-independence
    $result = $tm->assertAvailableEvents(['STOP', 'GO']);

    expect($result)->toBe($tm);
});

test('assertAvailableEvents fails when extra event present', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'aes_extra_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO'   => 'active',
                        'STOP' => 'done',
                    ],
                ],
                'active' => ['type' => 'final'],
                'done'   => ['type' => 'final'],
            ],
        ],
    );

    // Machine has GO + STOP, but we only assert GO
    expect(fn () => $tm->assertAvailableEvents(['GO']))
        ->toThrow(AssertionFailedError::class);
});

test('assertAvailableEvents fails when missing event', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'aes_missing_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    // Machine only has GO, but we assert GO + NONEXISTENT
    expect(fn () => $tm->assertAvailableEvents(['GO', 'NONEXISTENT']))
        ->toThrow(AssertionFailedError::class);
});

// ============================================================
// assertForwardAvailable()
// ============================================================

test('assertForwardAvailable passes for forward-sourced event', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    $childRecord  = MachineChild::first();
    $childMachine = ForwardChildEndpointMachine::create();
    $childRootId  = $childMachine->state->history->first()->root_event_id;
    $childMachine->persist();

    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    $result = TestMachine::for($parent)->assertForwardAvailable('PROVIDE_CARD');

    expect($result)->toBeInstanceOf(TestMachine::class);
});

test('assertForwardAvailable fails for parent-sourced event', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'fwd_fail_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    // GO has source 'parent', not 'forward'
    expect(fn () => $tm->assertForwardAvailable('GO'))
        ->toThrow(AssertionFailedError::class);
});

// ============================================================
// assertNoAvailableEvents()
// ============================================================

test('assertNoAvailableEvents passes on final state', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'no_events_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $result = $tm->send('GO')->assertNoAvailableEvents();

    expect($result)->toBe($tm);
});

test('assertNoAvailableEvents fails when events exist', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'no_events_fail_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    // In idle state with GO transition, assertNoAvailableEvents should fail
    expect(fn () => $tm->assertNoAvailableEvents())
        ->toThrow(AssertionFailedError::class);
});

// ============================================================
// Integration
// ============================================================

test('available events change as machine transitions through states', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'transition_flow_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'   => ['on' => ['GO' => 'active']],
                'active' => ['on' => ['FINISH' => 'done']],
                'done'   => ['type' => 'final'],
            ],
        ],
    );

    // In idle: only GO is available
    $tm->assertAvailableEvents(['GO']);

    // Send GO -> now in active: only FINISH is available
    $tm->send('GO')
        ->assertState('active')
        ->assertAvailableEvents(['FINISH']);

    // Send FINISH -> now in done (final): no events
    $tm->send('FINISH')
        ->assertState('done')
        ->assertNoAvailableEvents();
});

test('available events reflect forward config after entering delegating state', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    $childRecord  = MachineChild::first();
    $childMachine = ForwardChildEndpointMachine::create();
    $childRootId  = $childMachine->state->history->first()->root_event_id;
    $childMachine->persist();

    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    $tm = TestMachine::for($parent);

    // Parent on-event CANCEL should be available
    $tm->assertAvailableEvent('CANCEL');

    // Forward event PROVIDE_CARD should be available (child is in awaiting_card)
    $tm->assertForwardAvailable('PROVIDE_CARD');

    // CONFIRM_PAYMENT should NOT be available yet (child is in awaiting_card, not awaiting_confirmation)
    $result = $tm->assertNotAvailableEvent('CONFIRM_PAYMENT');

    expect($result)->toBe($tm);
});

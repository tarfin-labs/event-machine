<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\StateConfigValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FireAndForgetParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FireAndForgetAlwaysParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FireAndForgetTargetParentMachine;

uses(RefreshDatabase::class);

// ─── Fire-and-Forget Machine Config Validation ───────────────────

it('allows machine + queue without @done (fire-and-forget, stay in state)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'ff_valid',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    'on'      => ['FINISH' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

it('allows machine + queue + target without @done (fire-and-forget + transition)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'ff_target_valid',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'dispatching'],
                ],
                'dispatching' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    'target'  => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

it('allows machine + queue + on @always without @done (fire-and-forget + always)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'ff_always_valid',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'dispatching'],
                ],
                'dispatching' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    'on'      => ['@always' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

it('rejects fire-and-forget with @fail', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'ff_invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    '@fail'   => 'error',
                ],
                'error' => ['type' => 'final'],
            ],
        ],
    );
})->throws(InvalidArgumentException::class, "'@fail' without '@done'");

it('rejects fire-and-forget with @timeout', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'ff_invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine'  => ImmediateChildMachine::class,
                    'queue'    => true,
                    '@timeout' => ['timeout' => 30, 'target' => 'timed_out'],
                ],
                'timed_out' => ['type' => 'final'],
            ],
        ],
    );
})->throws(InvalidArgumentException::class, "'@timeout' without '@done'");

it('rejects fire-and-forget with output', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'ff_invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    'output'  => ['key'],
                ],
            ],
        ],
    );
})->throws(InvalidArgumentException::class, "'output' without '@done'");

it('rejects fire-and-forget with forward', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'ff_invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    'forward' => ['SOME_EVENT'],
                ],
            ],
        ],
    );
})->throws(InvalidArgumentException::class, "'forward' without '@done'");

it('rejects machine + target without queue', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'ff_invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine' => ImmediateChildMachine::class,
                    'target'  => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );
})->throws(InvalidArgumentException::class, "no 'queue'");

it('rejects machine + @done + target', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'ff_invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    '@done'   => 'done',
                    'target'  => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );
})->throws(InvalidArgumentException::class, "both '@done' and 'target'");

// ─── Fire-and-Forget: Stay in State ─────────────────────────────

it('parent stays in delegating state after fire-and-forget dispatch', function (): void {
    Queue::fake();

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-1');
    $state = $machine->send(['type' => 'START']);

    expect($state->currentStateDefinition->id)->toBe('ff_parent.processing');
});

it('parent can handle on events after fire-and-forget', function (): void {
    Queue::fake();

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-1');
    $machine->send(['type' => 'START']);
    $state = $machine->send(['type' => 'FINISH']);

    expect($state->value)->toBe(['ff_parent.completed']);
});

it('dispatches ChildMachineJob with fireAndForget flag', function (): void {
    Queue::fake();

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-1');
    $machine->send(['type' => 'START']);

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->childMachineClass === ImmediateChildMachine::class
            && $job->fireAndForget === true
            && $job->machineChildId === '';
    });
});

it('does not create MachineChild record', function (): void {
    Queue::fake();

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-1');
    $machine->send(['type' => 'START']);

    expect(DB::table('machine_children')->count())->toBe(0);
});

it('hasActiveChildren returns false after fire-and-forget', function (): void {
    Queue::fake();

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-1');
    $machine->send(['type' => 'START']);

    expect($machine->state->hasActiveChildren())->toBeFalse();
});

it('passes with context correctly', function (): void {
    Queue::fake();

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-789');
    $machine->send(['type' => 'START']);

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->childContext === ['orderId' => 'ORD-789'];
    });
});

// ─── Fire-and-Forget: @always Transition ────────────────────────

it('parent transitions via @always after fire-and-forget dispatch', function (): void {
    Queue::fake();

    $machine = FireAndForgetAlwaysParentMachine::create();
    $machine->state->context->set('tckn', '12345678901');
    $state = $machine->send(['type' => 'REJECT']);

    expect($state->currentStateDefinition->id)->toBe('ff_always_parent.prevented');
    Queue::assertPushed(ChildMachineJob::class);
});

it('parent can handle events in the @always target state', function (): void {
    Queue::fake();

    $machine = FireAndForgetAlwaysParentMachine::create();
    $machine->state->context->set('tckn', '12345678901');
    $machine->send(['type' => 'REJECT']);
    $state = $machine->send(['type' => 'RETRY']);

    expect($state->currentStateDefinition->id)->toBe('ff_always_parent.idle');
});

it('child dispatched with correct context via @always pattern', function (): void {
    Queue::fake();

    $machine = FireAndForgetAlwaysParentMachine::create();
    $machine->state->context->set('tckn', '12345678901');
    $machine->send(['type' => 'REJECT']);

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->childContext === ['tckn' => '12345678901']
            && $job->fireAndForget === true;
    });
});

// ─── Fire-and-Forget: target Transition ─────────────────────────

it('parent transitions to target after fire-and-forget dispatch', function (): void {
    Queue::fake();

    $machine = FireAndForgetTargetParentMachine::create();
    $machine->state->context->set('tckn', '12345678901');
    $state = $machine->send(['type' => 'REJECT']);

    expect($state->currentStateDefinition->id)->toBe('ff_target_parent.prevented');
});

it('dispatches to named queue with connection and retry', function (): void {
    Queue::fake();

    $machine = FireAndForgetTargetParentMachine::create();
    $machine->state->context->set('tckn', '12345678901');
    $machine->send(['type' => 'REJECT']);

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->queue === 'verifications'
            && $job->connection === 'redis'
            && $job->tries === 3;
    });
});

it('does not create MachineChild record with target', function (): void {
    Queue::fake();

    $machine = FireAndForgetTargetParentMachine::create();
    $machine->state->context->set('tckn', '12345678901');
    $machine->send(['type' => 'REJECT']);

    expect(DB::table('machine_children')->count())->toBe(0);
});

// ─── Fire-and-Forget ChildMachineJob Execution ──────────────────

it('ChildMachineJob persists child without dispatching completion', function (): void {
    Queue::fake();

    $job = new ChildMachineJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: FireAndForgetParentMachine::class,
        parentStateId: 'ff_parent.processing',
        childMachineClass: ImmediateChildMachine::class,
        machineChildId: '',
        childContext: [],
        retry: 1,
        fireAndForget: true,
    );

    $job->handle();

    expect(DB::table('machine_events')->count())->toBeGreaterThan(0);
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

it('ChildMachineJob does not touch MachineChild when fire-and-forget', function (): void {
    Queue::fake();

    $job = new ChildMachineJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: FireAndForgetParentMachine::class,
        parentStateId: 'ff_parent.processing',
        childMachineClass: ImmediateChildMachine::class,
        machineChildId: '',
        childContext: [],
        retry: 1,
        fireAndForget: true,
    );

    $job->handle();

    expect(DB::table('machine_children')->count())->toBe(0);
});

it('ChildMachineJob::failed returns early when fire-and-forget', function (): void {
    Queue::fake();

    $job = new ChildMachineJob(
        parentRootEventId: 'parent-root-id',
        parentMachineClass: FireAndForgetParentMachine::class,
        parentStateId: 'ff_parent.processing',
        childMachineClass: ImmediateChildMachine::class,
        machineChildId: '',
        fireAndForget: true,
    );

    $job->failed(new RuntimeException('Child exploded'));

    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

// ─── Machine::fake() with Fire-and-Forget ───────────────────────

it('fake short-circuits fire-and-forget, parent stays in state', function (): void {
    ImmediateChildMachine::fake(output: []);

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-1');
    $state = $machine->send(['type' => 'START']);

    expect($state->currentStateDefinition->id)->toBe('ff_parent.processing');
    ImmediateChildMachine::assertInvoked();
});

it('fake short-circuits fire-and-forget with target', function (): void {
    SimpleChildMachine::fake(output: []);

    $machine = FireAndForgetTargetParentMachine::create();
    $machine->state->context->set('tckn', '12345678901');
    $state = $machine->send(['type' => 'REJECT']);

    expect($state->currentStateDefinition->id)->toBe('ff_target_parent.prevented');
    SimpleChildMachine::assertInvoked();
});

it('fake records invocation with correct context', function (): void {
    ImmediateChildMachine::fake(output: []);

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-CTX');
    $machine->send(['type' => 'START']);

    $invocations = ImmediateChildMachine::getMachineInvocations();

    expect($invocations)->toHaveCount(1)
        ->and($invocations[0]['orderId'])->toBe('ORD-CTX');
});

it('fake ignores fail config for fire-and-forget', function (): void {
    ImmediateChildMachine::fake(fail: true, error: 'Nope');

    $machine = FireAndForgetParentMachine::create();
    $state   = $machine->send(['type' => 'START']);

    expect($state->currentStateDefinition->id)->toBe('ff_parent.processing');
});

// ─── Edge Cases & Regression ────────────────────────────────────

it('managed async delegation still creates MachineChild (regression)', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.processing');
    expect(DB::table('machine_children')->count())->toBe(1);
});

it('sync machine without @done stays in state (existing behavior preserved)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'sync_ff',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    'on'      => ['NEXT' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->currentStateDefinition->id)->toBe('sync_ff.delegating');
});

it('fire-and-forget child receives parent identity', function (): void {
    Queue::fake();

    $machine = FireAndForgetParentMachine::create();
    $machine->state->context->set('orderId', 'ORD-IDENTITY');
    $machine->send(['type' => 'START']);
    $machine->persist();

    $parentRootEventId = $machine->state->history->first()->root_event_id;

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job) use ($parentRootEventId): bool {
        return $job->parentRootEventId === $parentRootEventId
            && $job->parentMachineClass === FireAndForgetParentMachine::class;
    });
});

it('fire-and-forget with @always does not block on child', function (): void {
    Queue::fake();

    $machine = FireAndForgetAlwaysParentMachine::create();
    $machine->state->context->set('tckn', '12345');
    $state = $machine->send(['type' => 'REJECT']);

    expect($state->currentStateDefinition->id)->toBe('ff_always_parent.prevented');
    Queue::assertPushed(ChildMachineJob::class);
});

it('job fire-and-forget still works unchanged (regression)', function (): void {
    Queue::fake();

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'job_ff_regression',
            'initial' => 'idle',
            'context' => ['action' => 'login'],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'logging'],
                ],
                'logging' => [
                    'job'    => 'App\\Jobs\\AuditLogJob',
                    'input'  => ['action'],
                    'target' => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'START'], state: $state);

    expect($state->value)->toBe(['job_ff_regression.done']);
});

it('silently stays in state when target references nonexistent state', function (): void {
    Queue::fake();

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'ff_bad_target',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'dispatching'],
                ],
                'dispatching' => [
                    'machine' => ImmediateChildMachine::class,
                    'queue'   => true,
                    'target'  => 'nonexistent_state',
                ],
            ],
        ],
    );

    $machine->machineClass = 'App\\Machines\\TestMachine';

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // Parent stays in dispatching — target didn't resolve
    expect($state->currentStateDefinition->id)->toBe('ff_bad_target.dispatching');
    Queue::assertPushed(ChildMachineJob::class);
});

// ============================================================
// @done.{state} — Fire-and-Forget Interaction
// ============================================================

it('@done.{state} prevents fire-and-forget detection (T18)', function (): void {
    Queue::fake();

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'ff_done_dot',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine'        => MultiOutcomeChildMachine::class,
                    'queue'          => true,
                    '@done.approved' => 'completed',
                    '@done.rejected' => 'declined',
                    '@done.expired'  => 'declined',
                ],
                'completed' => ['type' => 'final'],
                'declined'  => ['type' => 'final'],
            ],
        ],
    );

    $machine->machineClass = 'App\\Machines\\TestMachine';

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // NOT fire-and-forget: MachineChild record should be created
    expect(DB::table('machine_children')->count())->toBe(1);

    // ChildMachineJob should NOT have fireAndForget flag
    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->fireAndForget === false;
    });
});

it('@done.{state} allows @fail when not fire-and-forget (T19)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                'queue'          => true,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done.expired'  => 'declined',
                '@fail'          => 'error',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
            'error'     => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

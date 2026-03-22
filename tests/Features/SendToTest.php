<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tarfinlabs\EventMachine\Context;
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ParentOrderMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

// ─── Sync sendTo ─────────────────────────────────────────────────

it('sends event to a target machine synchronously via sendTo', function (): void {
    // Create and persist a target machine
    $target      = SimpleChildMachine::create();
    $rootEventId = $target->state->history->first()->root_event_id;
    $target->persist();

    // Target starts in idle, can receive COMPLETE
    expect($target->state->currentStateDefinition->id)->toBe('simple_child.idle');

    // Create an action that calls sendTo
    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendTo(
                machineClass: SimpleChildMachine::class,
                rootEventId: $ctx->get('target_root_event_id'),
                event: ['type' => 'COMPLETE'],
            );
        }
    };

    // Execute the action (simulates being called within a machine)
    $action->__invoke(Context::from([
        'target_root_event_id' => $rootEventId,
    ]));

    // Verify: target machine received the event and transitioned
    $restored = SimpleChildMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('simple_child.done');
});

// ─── Async dispatchTo ───────────────────────────────────────────

it('dispatches SendToMachineJob via dispatchTo', function (): void {
    Queue::fake();

    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->dispatchTo(
                machineClass: SimpleChildMachine::class,
                rootEventId: 'some-root-event-id',
                event: ['type' => 'COMPLETE'],
            );
        }
    };

    $action->__invoke(Context::from([]));

    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job): bool {
        return $job->machineClass === SimpleChildMachine::class
            && $job->rootEventId === 'some-root-event-id'
            && $job->event['type'] === 'COMPLETE';
    });
});

// ─── SendToMachineJob ────────────────────────────────────────────

it('SendToMachineJob restores target and sends event', function (): void {
    // Create and persist target machine
    $target      = SimpleChildMachine::create();
    $rootEventId = $target->state->history->first()->root_event_id;
    $target->persist();

    // Dispatch and handle the job manually
    $job = new SendToMachineJob(
        machineClass: SimpleChildMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'COMPLETE'],
    );

    $job->handle();

    // Verify: target transitioned
    $restored = SimpleChildMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('simple_child.done');
});

it('SendToMachineJob logs warning for non-existent machine', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'target machine not found')
                && $context['root_event_id'] === 'nonexistent-root-id';
        });

    $job = new SendToMachineJob(
        machineClass: SimpleChildMachine::class,
        rootEventId: 'nonexistent-root-id',
        event: ['type' => 'COMPLETE'],
    );

    // Should not throw — handles gracefully
    $job->handle();
    expect(true)->toBeTrue();
});

it('SendToMachineJob logs warning when target machine cannot handle the event', function (): void {
    // Create and persist target machine in 'idle' state
    $target      = SimpleChildMachine::create();
    $rootEventId = $target->state->history->first()->root_event_id;
    $target->persist();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'failed to deliver event')
                && $context['event']['type'] === 'NONEXISTENT_EVENT';
        });

    $job = new SendToMachineJob(
        machineClass: SimpleChildMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'NONEXISTENT_EVENT'],
    );

    // Should not throw — handles gracefully
    $job->handle();
    expect(true)->toBeTrue();
});

// ─── sendToParent ────────────────────────────────────────────────

it('sendToParent throws on non-child machine', function (): void {
    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendToParent($ctx, ['type' => 'PROGRESS']);
        }
    };

    // Context without parent identity
    $ctx = Context::from([]);

    $action->__invoke($ctx);
})->throws(RuntimeException::class, 'Cannot sendToParent');

it('sendToParent sends event synchronously to parent', function (): void {
    // Use SimpleChildMachine as "parent" — it starts in idle, can receive COMPLETE
    $parentMachine = SimpleChildMachine::create();
    $parentMachine->persist();
    $parentRootEventId = $parentMachine->state->history->first()->root_event_id;

    expect($parentMachine->state->currentStateDefinition->id)->toBe('simple_child.idle');

    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendToParent($ctx, ['type' => 'COMPLETE']);
        }
    };

    // Create child context with parent identity
    $ctx = Context::from([]);
    $ctx->setMachineIdentity(
        machineId: 'child-id',
        parentRootEventId: $parentRootEventId,
        parentMachineClass: SimpleChildMachine::class,
    );

    $action->__invoke($ctx);

    // Verify: parent machine received the COMPLETE event and transitioned to done
    $restored = SimpleChildMachine::create(state: $parentRootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('simple_child.done');
});

// ─── dispatchToParent ────────────────────────────────────────────

it('dispatchToParent dispatches SendToMachineJob to parent', function (): void {
    Queue::fake();

    $parent            = ParentOrderMachine::create();
    $parentRootEventId = $parent->state->history->first()->root_event_id;

    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->dispatchToParent($ctx, ['type' => 'START_PAYMENT']);
        }
    };

    // Create context with parent identity set
    $ctx = Context::from([]);
    $ctx->setMachineIdentity(
        machineId: 'child-root-event-id',
        parentRootEventId: $parentRootEventId,
        parentMachineClass: ParentOrderMachine::class,
    );

    $action->__invoke($ctx);

    // Verify the job was dispatched with parent's details
    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job) use ($parentRootEventId): bool {
        return $job->machineClass === ParentOrderMachine::class
            && $job->rootEventId === $parentRootEventId
            && $job->event['type'] === 'START_PAYMENT';
    });
});

it('dispatchToParent throws on non-child machine', function (): void {
    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->dispatchToParent($ctx, ['type' => 'PROGRESS']);
        }
    };

    $ctx = Context::from([]);

    $action->__invoke($ctx);
})->throws(RuntimeException::class, 'Cannot dispatchToParent');

it('dispatchTo converts EventBehavior to array for dispatch', function (): void {
    Queue::fake();

    $action = new class() extends ActionBehavior {
        public function __invoke(): void
        {
            $event = EventDefinition::from([
                'type'    => 'CUSTOM_EVENT',
                'payload' => ['key' => 'value'],
                'version' => 1,
                'source'  => SourceType::EXTERNAL,
            ]);

            $this->dispatchTo(
                machineClass: SimpleChildMachine::class,
                rootEventId: 'some-id',
                event: $event,
            );
        }
    };

    $action->__invoke();

    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job): bool {
        return $job->event['type'] === 'CUSTOM_EVENT'
            && $job->event['payload']['key'] === 'value';
    });
});

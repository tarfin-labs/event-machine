<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
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
    $action->__invoke(ContextManager::validateAndCreate([
        'data' => ['target_root_event_id' => $rootEventId],
    ]));

    // Verify: target machine received the event and transitioned
    $restored = SimpleChildMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('simple_child.done');
});

// ─── Async sendTo ────────────────────────────────────────────────

it('dispatches SendToMachineJob when async mode is used', function (): void {
    Queue::fake();

    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendTo(
                machineClass: SimpleChildMachine::class,
                rootEventId: 'some-root-event-id',
                event: ['type' => 'COMPLETE'],
                async: true,
            );
        }
    };

    $action->__invoke(ContextManager::validateAndCreate([
        'data' => [],
    ]));

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

// ─── sendToParent ────────────────────────────────────────────────

it('sendToParent throws on non-child machine', function (): void {
    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendToParent($ctx, ['type' => 'PROGRESS']);
        }
    };

    // Context without parent identity
    $ctx = ContextManager::validateAndCreate(['data' => []]);

    $action->__invoke($ctx);
})->throws(RuntimeException::class, 'Cannot sendToParent');

it('sendToParent resolves parent identity from context', function (): void {
    Queue::fake();

    // Create a parent machine first
    $parent            = ParentOrderMachine::create();
    $parentRootEventId = $parent->state->history->first()->root_event_id;

    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendToParent($ctx, ['type' => 'START_PAYMENT'], async: true);
        }
    };

    // Create context with parent identity set
    $ctx = ContextManager::validateAndCreate(['data' => []]);
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

it('sendTo converts EventBehavior to array for async dispatch', function (): void {
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

            $this->sendTo(
                machineClass: SimpleChildMachine::class,
                rootEventId: 'some-id',
                event: $event,
                async: true,
            );
        }
    };

    $action->__invoke();

    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job): bool {
        return $job->event['type'] === 'CUSTOM_EVENT'
            && $job->event['payload']['key'] === 'value';
    });
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

// ─── Progress Reporting: child → parent via sendToParent ─────────

it('child reports progress to parent via sendToParent and parent updates context', function (): void {
    // Create a parent machine that handles CHILD_PROGRESS on its processing state.
    // The parent delegates to a child that immediately completes (sync).
    // We test that sendToParent dispatches correctly.

    // Since sync delegation runs child inline and immediately routes @done,
    // sendToParent during a sync child would need to send back to an already-transitioning parent.
    // The real use case is async: child runs on queue, sends progress events back.
    // For this test, we verify sendToParent dispatches SendToMachineJob (async path).

    Queue::fake();

    // Simulate: a child action calls sendToParent with progress data
    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendToParent($ctx, [
                'type'    => 'CHILD_PROGRESS',
                'payload' => [
                    'percent'   => 50,
                    'processed' => 5,
                    'total'     => 10,
                ],
            ], async: true);
        }
    };

    // Set up context as if this is a child machine with a parent
    $ctx = ContextManager::validateAndCreate(['data' => []]);
    $ctx->setMachineIdentity(
        machineId: 'child-machine-id',
        parentRootEventId: 'parent-root-event-id',
        parentMachineClass: 'App\\Machines\\ParentMachine',
    );

    $action->__invoke($ctx);

    // Verify: SendToMachineJob dispatched with parent details and progress event
    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job): bool {
        return $job->machineClass === 'App\\Machines\\ParentMachine'
            && $job->rootEventId === 'parent-root-event-id'
            && $job->event['type'] === 'CHILD_PROGRESS'
            && $job->event['payload']['percent'] === 50
            && $job->event['payload']['processed'] === 5
            && $job->event['payload']['total'] === 10;
    });
});

it('parent machine handles progress event on its on map', function (): void {
    // Create a parent machine with a state that has 'on' handlers for CHILD_PROGRESS
    $parentMachine = Machine::withDefinition(
        MachineDefinition::define(
            config: [
                'id'      => 'progress_parent',
                'initial' => 'processing',
                'context' => [
                    'progress_percent' => 0,
                ],
                'states' => [
                    'processing' => [
                        'on' => [
                            'CHILD_PROGRESS' => [
                                'actions' => 'updateProgressAction',
                            ],
                            'DONE' => 'completed',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'updateProgressAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('progress_percent', $event->payload['percent'] ?? 0);
                    },
                ],
            ],
        )
    );

    $parentMachine->start();

    // Parent starts in processing
    expect($parentMachine->state->currentStateDefinition->id)->toBe('progress_parent.processing');

    // Send CHILD_PROGRESS — parent stays in state, updates context
    $parentMachine->send([
        'type'    => 'CHILD_PROGRESS',
        'payload' => ['percent' => 25],
    ]);

    expect($parentMachine->state->currentStateDefinition->id)->toBe('progress_parent.processing')
        ->and($parentMachine->state->context->get('progress_percent'))->toBe(25);

    // Send another progress update
    $parentMachine->send([
        'type'    => 'CHILD_PROGRESS',
        'payload' => ['percent' => 75],
    ]);

    expect($parentMachine->state->context->get('progress_percent'))->toBe(75);

    // Finally complete
    $parentMachine->send(['type' => 'DONE']);
    expect($parentMachine->state->currentStateDefinition->id)->toBe('progress_parent.completed');
});

it('sendToParent with sync mode sends event directly to parent', function (): void {
    // Use SimpleChildMachine as "parent" — it starts in idle, can receive COMPLETE
    $parentMachine = SimpleChildMachine::create();
    $parentMachine->persist();
    $parentRootEventId = $parentMachine->state->history->first()->root_event_id;

    expect($parentMachine->state->currentStateDefinition->id)->toBe('simple_child.idle');

    // Simulate a child action calling sendToParent synchronously with COMPLETE event
    $action = new class() extends ActionBehavior {
        public function __invoke(ContextManager $ctx): void
        {
            $this->sendToParent($ctx, ['type' => 'COMPLETE']);
        }
    };

    // Create child context with parent identity
    $ctx = ContextManager::validateAndCreate(['data' => []]);
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

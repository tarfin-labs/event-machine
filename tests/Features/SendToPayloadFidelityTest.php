<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ═══════════════════════════════════════════════════════════════
//  Bead 4: sendTo payload type fidelity through serialization.
//  Send event with complex payload via sendTo. Verify target
//  machine receives exact same types (int stays int, not string).
// ═══════════════════════════════════════════════════════════════

it('sendTo preserves payload types through synchronous cross-machine call', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'send_to_target',
            'initial' => 'waiting',
            'context' => [
                'received_payload' => null,
            ],
            'states' => [
                'waiting' => [
                    'on' => [
                        'DELIVER' => [
                            'target'  => 'received',
                            'actions' => 'capturePayloadAction',
                        ],
                    ],
                ],
                'received' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capturePayloadAction' => function (ContextManager $context, EventBehavior $event): void {
                    $context->set('received_payload', $event->payload);
                },
            ],
        ],
    );

    $target = Machine::create($definition);
    $target->persist();
    $targetRootEventId = $target->state->history->first()->root_event_id;

    // Complex payload with various types
    $complexPayload = [
        'integer_value' => 42,
        'float_value'   => 3.14,
        'bool_true'     => true,
        'bool_false'    => false,
        'null_value'    => null,
        'string_value'  => 'hello world',
        'nested_array'  => [
            'deep_int'    => 99,
            'deep_string' => 'nested',
            'deep_list'   => [1, 2, 3],
        ],
        'empty_array' => [],
    ];

    // Simulate sendTo by restoring and sending directly (same as InvokableBehavior::sendTo)
    $targetMachine = Machine::create($definition, state: $targetRootEventId);
    $targetMachine->send([
        'type'    => 'DELIVER',
        'payload' => $complexPayload,
    ]);

    // Verify received payload matches exactly
    $receivedPayload = $targetMachine->state->context->get('received_payload');

    expect($receivedPayload['integer_value'])->toBe(42)
        ->and($receivedPayload['integer_value'])->toBeInt()
        ->and($receivedPayload['float_value'])->toBe(3.14)
        ->and($receivedPayload['float_value'])->toBeFloat()
        ->and($receivedPayload['bool_true'])->toBeTrue()
        ->and($receivedPayload['bool_false'])->toBeFalse()
        ->and($receivedPayload['null_value'])->toBeNull()
        ->and($receivedPayload['string_value'])->toBe('hello world')
        ->and($receivedPayload['nested_array'])->toBe([
            'deep_int'    => 99,
            'deep_string' => 'nested',
            'deep_list'   => [1, 2, 3],
        ])
        ->and($receivedPayload['empty_array'])->toBe([]);
});

it('SendToMachineJob preserves payload types through serialization round-trip', function (): void {
    $complexPayload = [
        'amount'   => 1500,
        'rate'     => 0.18,
        'approved' => true,
        'metadata' => ['source' => 'api', 'version' => 2],
        'tags'     => ['urgent', 'vip'],
        'notes'    => null,
    ];

    // Create the job and simulate queue round-trip serialization
    $job = new SendToMachineJob(
        machineClass: Machine::class,
        rootEventId: 'test-root-event-id',
        event: ['type' => 'DELIVER', 'payload' => $complexPayload],
    );

    // Serialize and unserialize to simulate queue round-trip
    $serialized   = serialize($job);
    $deserialized = unserialize($serialized);

    // Verify event payload survived serialization
    expect($deserialized->event['payload']['amount'])->toBe(1500)
        ->and($deserialized->event['payload']['amount'])->toBeInt()
        ->and($deserialized->event['payload']['rate'])->toBe(0.18)
        ->and($deserialized->event['payload']['rate'])->toBeFloat()
        ->and($deserialized->event['payload']['approved'])->toBeTrue()
        ->and($deserialized->event['payload']['metadata'])->toBe(['source' => 'api', 'version' => 2])
        ->and($deserialized->event['payload']['tags'])->toBe(['urgent', 'vip'])
        ->and($deserialized->event['payload']['notes'])->toBeNull();
});

it('payload survives persist and restore cycle with type fidelity', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'persist_restore_fidelity',
            'initial' => 'idle',
            'context' => [
                'captured' => null,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'STORE' => [
                            'target'  => 'stored',
                            'actions' => 'captureAction',
                        ],
                    ],
                ],
                'stored' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'captureAction' => function (ContextManager $context, EventBehavior $event): void {
                    $context->set('captured', $event->payload);
                },
            ],
        ],
    );

    $machine = Machine::create($definition);
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->send([
        'type'    => 'STORE',
        'payload' => [
            'int_val'   => 7,
            'float_val' => 2.71828,
            'bool_val'  => false,
            'list'      => [10, 20, 30],
        ],
    ]);

    // Restore from DB and verify types survived the round-trip
    $restored = Machine::create($definition, state: $rootEventId);
    $captured = $restored->state->context->get('captured');

    expect($captured['int_val'])->toBe(7)
        ->and($captured['int_val'])->toBeInt()
        ->and($captured['float_val'])->toBe(2.71828)
        ->and($captured['float_val'])->toBeFloat()
        ->and($captured['bool_val'])->toBeFalse()
        ->and($captured['list'])->toBe([10, 20, 30]);
});

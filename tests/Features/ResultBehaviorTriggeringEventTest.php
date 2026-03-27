<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ═══════════════════════════════════════════════════════════════
//  ResultBehavior receives triggeringEvent, not internal events
// ═══════════════════════════════════════════════════════════════

it('Machine::result() receives the original event payload, not internal event', function (): void {
    $machine = Machine::withDefinition(
        definition: MachineDefinition::define(
            config: [
                'id'      => 'result_payload',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => ['on' => ['SUBMIT' => 'done']],
                    'done' => [
                        'type'   => 'final',
                        'result' => fn (EventBehavior $event): array => $event->payload,
                    ],
                ],
            ],
        ),
    );

    $machine->send(['type' => 'SUBMIT', 'payload' => ['tckn' => '12345678901', 'amount' => 500]]);

    $result = $machine->result();

    expect($result)
        ->toBe(['tckn' => '12345678901', 'amount' => 500]);
});

it('Machine::result() receives event type, not internal event type', function (): void {
    $machine = Machine::withDefinition(
        definition: MachineDefinition::define(
            config: [
                'id'      => 'result_type',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => ['on' => ['COMPLETE' => 'done']],
                    'done' => [
                        'type'   => 'final',
                        'result' => fn (EventBehavior $event): string => $event->type,
                    ],
                ],
            ],
        ),
    );

    $machine->send(['type' => 'COMPLETE']);

    $result = $machine->result();

    // Should be the original event type, not an internal event like STATE_ENTRY_FINISH
    expect($result)->toBe('COMPLETE');
});

it('Machine::result() works when no event was sent (initial final state)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_no_event',
            'initial' => 'done',
            'context' => ['value' => 99],
            'states'  => [
                'done' => [
                    'type'   => 'final',
                    'result' => fn (ContextManager $context, EventBehavior $event): array => [
                        'value'      => $context->get('value'),
                        'event_type' => $event->type,
                    ],
                ],
            ],
        ],
    );

    $state          = $definition->getInitialState();
    $resultBehavior = $definition->behavior['results']['result_no_event.done'];
    $params         = InvokableBehavior::injectInvokableBehaviorParameters(
        actionBehavior: $resultBehavior,
        state: $state,
        eventBehavior: $state->triggeringEvent ?? $state->currentEventBehavior,
    );
    $result = $resultBehavior(...$params);

    // triggeringEvent is null → falls back to currentEventBehavior
    expect($result['value'])->toBe(99)
        ->and($result['event_type'])->toBeString();
});

it('Machine::result() preserves payload through entry actions', function (): void {
    $machine = Machine::withDefinition(
        definition: MachineDefinition::define(
            config: [
                'id'      => 'result_through_actions',
                'initial' => 'idle',
                'context' => ['processed' => false],
                'states'  => [
                    'idle' => ['on' => ['PROCESS' => 'done']],
                    'done' => [
                        'type'   => 'final',
                        'entry'  => 'markProcessedAction',
                        'result' => fn (EventBehavior $event, ContextManager $ctx): array => [
                            'orderId'   => $event->payload['orderId'] ?? null,
                            'processed' => $ctx->get('processed'),
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'markProcessedAction' => function (ContextManager $ctx): void {
                        $ctx->set('processed', true);
                    },
                ],
            ],
        ),
    );

    $machine->send(['type' => 'PROCESS', 'payload' => ['orderId' => 'ORD-42']]);

    $result = $machine->result();

    // Entry action ran (processed=true), but event payload is still the original PROCESS event
    expect($result['orderId'])->toBe('ORD-42')
        ->and($result['processed'])->toBeTrue();
});

it('Machine::result() preserves payload through @always chain', function (): void {
    $machine = Machine::withDefinition(
        definition: MachineDefinition::define(
            config: [
                'id'      => 'result_always_chain',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'         => ['on' => ['START' => 'intermediate']],
                    'intermediate' => [
                        'on' => ['@always' => 'done'],
                    ],
                    'done' => [
                        'type'   => 'final',
                        'result' => fn (EventBehavior $event): array => [
                            'type'    => $event->type,
                            'payload' => $event->payload,
                        ],
                    ],
                ],
            ],
        ),
    );

    $machine->send(['type' => 'START', 'payload' => ['ref' => 'ABC']]);

    $result = $machine->result();

    // Should receive the original START event, not @always
    expect($result['type'])->toBe('START')
        ->and($result['payload']['ref'])->toBe('ABC');
});

it('Machine::result() receives the last triggering event in multi-step flow', function (): void {
    $machine = Machine::withDefinition(
        definition: MachineDefinition::define(
            config: [
                'id'      => 'result_multi_step',
                'initial' => 'step1',
                'context' => [],
                'states'  => [
                    'step1' => ['on' => ['NEXT' => 'step2']],
                    'step2' => ['on' => ['FINISH' => 'done']],
                    'done'  => [
                        'type'   => 'final',
                        'result' => fn (EventBehavior $event): array => $event->payload,
                    ],
                ],
            ],
        ),
    );

    $machine->send(['type' => 'NEXT']);
    $machine->send(['type' => 'FINISH', 'payload' => ['final_data' => true]]);

    $result = $machine->result();

    // Should receive the FINISH event (last triggering event), not NEXT
    expect($result)->toBe(['final_data' => true]);
});

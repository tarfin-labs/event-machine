<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// region Stub Machine

class ContextRoundTripMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'context_round_trip',
                'initial' => 'idle',
                'context' => [
                    'intVal'      => 42,
                    'floatVal'    => 3.14,
                    'stringVal'   => 'hello',
                    'boolTrue'    => true,
                    'boolFalse'   => false,
                    'nullVal'     => null,
                    'nestedArray' => ['a' => 1, 'b' => ['c' => 2]],
                    'emptyArray'  => [],
                    'zeroInt'     => 0,
                    'emptyString' => '',
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'NEXT' => 'done',
                        ],
                    ],
                    'done' => [
                        'on' => [
                            'MODIFY' => [
                                'actions' => 'modifyContextAction',
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'modifyContextAction' => function (ContextManager $context): void {
                        $context->set('intVal', 99);
                    },
                ],
            ],
        );
    }
}

// endregion

it('preserves context data types through persist and restore cycle', function (): void {
    // Create machine and send an event to persist state
    $machine = ContextRoundTripMachine::create();
    $machine->send(['type' => 'NEXT']);

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from persisted state
    $restored = ContextRoundTripMachine::create(state: $rootEventId);
    $ctx      = $restored->state->context;

    // Strict type assertions — assertSame checks both value AND type
    expect($ctx->get('intVal'))->toBe(42)
        ->and($ctx->get('intVal'))->toBeInt();

    expect($ctx->get('floatVal'))->toBe(3.14)
        ->and($ctx->get('floatVal'))->toBeFloat();

    expect($ctx->get('stringVal'))->toBe('hello')
        ->and($ctx->get('stringVal'))->toBeString();

    expect($ctx->get('boolTrue'))->toBe(true)
        ->and($ctx->get('boolTrue'))->toBeBool();

    expect($ctx->get('boolFalse'))->toBe(false)
        ->and($ctx->get('boolFalse'))->toBeBool();

    expect($ctx->get('nullVal'))->toBeNull();

    expect($ctx->get('nestedArray'))->toBe(['a' => 1, 'b' => ['c' => 2]])
        ->and($ctx->get('nestedArray'))->toBeArray();

    expect($ctx->get('emptyArray'))->toBe([])
        ->and($ctx->get('emptyArray'))->toBeArray();

    expect($ctx->get('zeroInt'))->toBe(0)
        ->and($ctx->get('zeroInt'))->toBeInt();

    expect($ctx->get('emptyString'))->toBe('')
        ->and($ctx->get('emptyString'))->toBeString();
});

it('preserves context data types after context modification and restore', function (): void {
    // Create, transition, modify context, then restore
    $machine = ContextRoundTripMachine::create();
    $machine->send(['type' => 'NEXT']);
    $machine->send(['type' => 'MODIFY']);

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from persisted state
    $restored = ContextRoundTripMachine::create(state: $rootEventId);
    $ctx      = $restored->state->context;

    // The modified value should be int 99, not string "99"
    expect($ctx->get('intVal'))->toBe(99)
        ->and($ctx->get('intVal'))->toBeInt();

    // All other values should remain intact with correct types
    expect($ctx->get('floatVal'))->toBe(3.14);
    expect($ctx->get('boolTrue'))->toBe(true);
    expect($ctx->get('boolFalse'))->toBe(false);
    expect($ctx->get('nullVal'))->toBeNull();
    expect($ctx->get('nestedArray'))->toBe(['a' => 1, 'b' => ['c' => 2]]);
    expect($ctx->get('emptyArray'))->toBe([]);
    expect($ctx->get('zeroInt'))->toBe(0);
    expect($ctx->get('emptyString'))->toBe('');
});

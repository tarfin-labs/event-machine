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
                    'int_val'      => 42,
                    'float_val'    => 3.14,
                    'string_val'   => 'hello',
                    'bool_true'    => true,
                    'bool_false'   => false,
                    'null_val'     => null,
                    'nested_array' => ['a' => 1, 'b' => ['c' => 2]],
                    'empty_array'  => [],
                    'zero_int'     => 0,
                    'empty_string' => '',
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
                        $context->set('int_val', 99);
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
    expect($ctx->get('int_val'))->toBe(42)
        ->and($ctx->get('int_val'))->toBeInt();

    expect($ctx->get('float_val'))->toBe(3.14)
        ->and($ctx->get('float_val'))->toBeFloat();

    expect($ctx->get('string_val'))->toBe('hello')
        ->and($ctx->get('string_val'))->toBeString();

    expect($ctx->get('bool_true'))->toBe(true)
        ->and($ctx->get('bool_true'))->toBeBool();

    expect($ctx->get('bool_false'))->toBe(false)
        ->and($ctx->get('bool_false'))->toBeBool();

    expect($ctx->get('null_val'))->toBeNull();

    expect($ctx->get('nested_array'))->toBe(['a' => 1, 'b' => ['c' => 2]])
        ->and($ctx->get('nested_array'))->toBeArray();

    expect($ctx->get('empty_array'))->toBe([])
        ->and($ctx->get('empty_array'))->toBeArray();

    expect($ctx->get('zero_int'))->toBe(0)
        ->and($ctx->get('zero_int'))->toBeInt();

    expect($ctx->get('empty_string'))->toBe('')
        ->and($ctx->get('empty_string'))->toBeString();
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
    expect($ctx->get('int_val'))->toBe(99)
        ->and($ctx->get('int_val'))->toBeInt();

    // All other values should remain intact with correct types
    expect($ctx->get('float_val'))->toBe(3.14);
    expect($ctx->get('bool_true'))->toBe(true);
    expect($ctx->get('bool_false'))->toBe(false);
    expect($ctx->get('null_val'))->toBeNull();
    expect($ctx->get('nested_array'))->toBe(['a' => 1, 'b' => ['c' => 2]]);
    expect($ctx->get('empty_array'))->toBe([]);
    expect($ctx->get('zero_int'))->toBe(0);
    expect($ctx->get('empty_string'))->toBe('');
});

<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\StateConfigValidator;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\PaymentFailure;

// ═══════════════════════════════════════════════════════════════
//  Input Validation
// ═══════════════════════════════════════════════════════════════

test('valid input class passes validation', function (): void {
    StateConfigValidator::validate([
        'id'      => 'input_valid',
        'initial' => 'idle',
        'input'   => PaymentInput::class,
        'states'  => [
            'idle' => ['type' => 'final'],
        ],
    ]);

    // No exception — validation passed
    expect(true)->toBeTrue();
});

test('invalid input class (not MachineInput subclass) throws', function (): void {
    StateConfigValidator::validate([
        'id'      => 'input_invalid',
        'initial' => 'idle',
        'input'   => stdClass::class,
        'states'  => [
            'idle' => ['type' => 'final'],
        ],
    ]);
})->throws(InvalidArgumentException::class, "Root 'input' key must be a MachineInput subclass");

test('input as array passes validation', function (): void {
    StateConfigValidator::validate([
        'id'      => 'input_array',
        'initial' => 'idle',
        'input'   => ['orderId'],
        'states'  => [
            'idle' => ['type' => 'final'],
        ],
    ]);

    // No exception — arrays are accepted as untyped input declarations
    expect(true)->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════
//  Failure Validation
// ═══════════════════════════════════════════════════════════════

test('valid failure class passes validation', function (): void {
    StateConfigValidator::validate([
        'id'      => 'failure_valid',
        'initial' => 'idle',
        'failure' => PaymentFailure::class,
        'states'  => [
            'idle' => ['type' => 'final'],
        ],
    ]);

    // No exception — validation passed
    expect(true)->toBeTrue();
});

test('invalid failure class throws', function (): void {
    StateConfigValidator::validate([
        'id'      => 'failure_invalid',
        'initial' => 'idle',
        'failure' => stdClass::class,
        'states'  => [
            'idle' => ['type' => 'final'],
        ],
    ]);
})->throws(InvalidArgumentException::class, "Root 'failure' key must be a MachineFailure subclass");

test('failure with non-string value throws', function (): void {
    StateConfigValidator::validate([
        'id'      => 'failure_nonstring',
        'initial' => 'idle',
        'failure' => 123,
        'states'  => [
            'idle' => ['type' => 'final'],
        ],
    ]);
})->throws(InvalidArgumentException::class, "Root 'failure' key must be a MachineFailure subclass");

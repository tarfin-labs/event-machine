<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('validates root level configuration keys', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'              => 'machine',
        'invalid_key'     => 'value',
        'another_invalid' => 'value',
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: 'Invalid root level configuration keys: invalid_key, another_invalid. Allowed keys are: id, version, initial, context, states, on, type, meta, entry, exit, description, scenarios_enabled, should_persist, delimiter'
    );
});

test('accepts valid root level configuration', function (): void {
    // HINT: This test should contain all possible root level configuration keys
    expect(fn () => MachineDefinition::define([
        'id'                => 'machine',
        'version'           => '1.0.0',
        'initial'           => 'state_a',
        'context'           => ['some' => 'data'],
        'scenarios_enabled' => true,
        'should_persist'    => true,
        'delimiter'         => '.',
        'states'            => [
            'state_a' => [],
        ],
    ]))->not->toThrow(exception: InvalidArgumentException::class);
});

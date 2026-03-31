<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\InvalidStateConfigException;

it('accepts valid listen config', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'valid_listen',
            'initial' => 'idle',
            'listen'  => [
                'entry'      => 'someAction',
                'exit'       => 'someAction',
                'transition' => 'someAction',
            ],
            'states' => [
                'idle' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'someAction' => function (): void {},
            ],
        ],
    );

    expect($definition)->toBeInstanceOf(MachineDefinition::class);
});

it('rejects invalid listen keys', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'invalid_listen',
            'initial' => 'idle',
            'listen'  => [
                'invalid_key' => 'someAction',
            ],
            'states' => [
                'idle' => [],
            ],
        ],
    );
})->throws(InvalidStateConfigException::class, "Invalid 'listen' keys: invalid_key");

it('rejects non-array listen value', function (): void {
    MachineDefinition::define(
        config: [
            'id'      => 'string_listen',
            'initial' => 'idle',
            'listen'  => 'not_an_array',
            'states'  => [
                'idle' => [],
            ],
        ],
    );
})->throws(InvalidStateConfigException::class, 'must be an array');

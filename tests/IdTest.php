<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

test('machine id should represent the ID', function (): void {
    $idMachine = Machine::define([
        'id'      => 'some-id',
        'initial' => 'idle',
        'states'  => [
            'idle' => [],
        ],
    ]);

    expect($idMachine->id)->toBe('some-id');
});

test('machine id should represent the id (state node)', function (): void {
    $idMachine = Machine::define([
        'id'      => 'some-id',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'id' => 'idle',
            ],
        ],
    ]);

    expect($idMachine->states['idle']->id)->toBe('idle');
});

test('machine id should use the key as the ID if no ID is provided (state node)', function (): void {
    $noStateNodeIDMachine = Machine::define([
        'id'      => 'some-id',
        'initial' => 'idle',
        'states'  => [
            'idle' => [],
        ],
    ]);

    expect($noStateNodeIDMachine->states['idle']->id)->toBe('some-id.idle');
});

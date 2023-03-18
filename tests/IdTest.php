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

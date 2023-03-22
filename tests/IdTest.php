<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

test('id represent root state definition', function (): void {
    $idMachine = MachineDefinition::define(config: [
        'id' => 'some-id',
        'states' => [
            'idle' => [],
        ],
    ]);

    expect($idMachine->id)->toBe('some-id');
});

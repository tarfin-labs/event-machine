<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

test('a Machine with a negative version', function (): void {
    $machine = Machine::define([
        'version' => -2,
    ]);

    expect($machine)->version->toBe(1);
});

<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Facades\Machine;
use Tarfinlabs\EventMachine\State;

test('a machine is an instance of State::class', function (): void {
    $machine = Machine::define();

    expect($machine)->toBeInstanceOf(State::class);
});

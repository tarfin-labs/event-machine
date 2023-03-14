<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Facades\Machine;

test('a machine is an instance of State::class', function (): void {
    $machine = Machine::define();

    expect($machine)->toBeInstanceOf(State::class);
});

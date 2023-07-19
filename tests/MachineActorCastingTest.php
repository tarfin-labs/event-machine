<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

test('MachineActor can be cast to json', function (): void {
    $machine = XyzMachine::start();

    expect(json_encode($machine, JSON_THROW_ON_ERROR))
        ->toBe('"'.$machine->state->history->first()->root_event_id.'"');
});

test('MachineActor can be cast to string', function (): void {
    $machine = XyzMachine::start();

    expect((string) $machine)
        ->toBe($machine->state->history->first()->root_event_id);
});

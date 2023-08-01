<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

test('Machine can be cast to JSON', function (): void {
    $machine = XyzMachine::create();

    expect(json_encode($machine, JSON_THROW_ON_ERROR))
        ->toBe('"'.$machine->state->history->first()->root_event_id.'"');
});

test('Machine can be cast to string', function (): void {
    $machine = XyzMachine::create();

    expect((string) $machine)
        ->toBe($machine->state->history->first()->root_event_id);
});

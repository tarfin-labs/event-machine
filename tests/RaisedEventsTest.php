<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

test('actions can raise events', function (): void {
    $machine = XyzMachine::start();

    expect($machine->state->matches('#z'))->toBeTrue();
    expect($machine->state->context->value)->toBe('xyz');
    expect($machine->state->history->pluck('type')->toArray())->toBe([
        'machine.init',
        'machine.action.!x.init',
        'machine.action.!x.event_raised',
        'machine.action.!x.done',
        '@x',
        'machine.state.#x.init',
        'machine.action.!y.init',
        'machine.action.!y.event_raised',
        'machine.action.!y.done',
        '@y',
        'machine.state.#y.init',
        'machine.action.!z.init',
        'machine.action.!z.event_raised',
        'machine.action.!z.done',
        '@z',
        'machine.state.#z.init',
    ]);
});

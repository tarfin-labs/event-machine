<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

test('actions can raise events', function (): void {
    $machine = XyzMachine::start();

    expect($machine->state->matches('#z'))->toBeTrue();
    expect($machine->state->context->value)->toBe('xyz');
    expect($machine->state->history->pluck('type')->toArray())->toBe([
        'xyz.init',
        'xyz.action.!x.init',
        'xyz.action.!x.event_raised',
        'xyz.action.!x.done',
        '@x',
        'xyz.state.#x.init',
        'xyz.action.!y.init',
        'xyz.action.!y.event_raised',
        'xyz.action.!y.done',
        '@y',
        'xyz.state.#y.init',
        'xyz.action.!z.init',
        'xyz.action.!z.event_raised',
        'xyz.action.!z.done',
        '@z',
        'xyz.state.#z.init',
    ]);
});

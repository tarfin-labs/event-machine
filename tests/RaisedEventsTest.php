<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

test('actions can raise events', function (): void {
    $machine = XyzMachine::start();

    expect($machine->state->matches('#z'))->toBeTrue();
    expect($machine->state->context->value)->toBe('xyz');
    expect($machine->state->history->pluck('type')->toArray())->toBe([
        'xyz.start',
        'xyz.state.#a.enter',
        'xyz.action.!x.start',
        'xyz.action.!x.event_raised',
        'xyz.action.!x.finish',
        '@x',
        'xyz.state.#x.enter',
        'xyz.action.!y.start',
        'xyz.action.!y.event_raised',
        'xyz.action.!y.finish',
        '@y',
        'xyz.state.#y.enter',
        'xyz.action.!z.start',
        'xyz.action.!z.event_raised',
        'xyz.action.!z.finish',
        '@z',
        'xyz.state.#z.enter',
    ]);
});

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
        'xyz.event.@x.raised',
        'xyz.action.!x.finish',
        '@x',
        'xyz.state.#a.exit',
        'xyz.state.#x.enter',
        'xyz.action.!y.start',
        'xyz.event.@y.raised',
        'xyz.action.!y.finish',
        '@y',
        'xyz.state.#x.exit',
        'xyz.state.#y.enter',
        'xyz.action.!z.start',
        'xyz.event.@z.raised',
        'xyz.action.!z.finish',
        '@z',
        'xyz.state.#y.exit',
        'xyz.state.#z.enter',
    ]);
});

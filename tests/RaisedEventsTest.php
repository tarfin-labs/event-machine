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
        'xyz.state.#a.entry.start',
        'xyz.action.!x.start',
        'xyz.event.@x.raised',
        'xyz.action.!x.finish',
        'xyz.state.#a.entry.finish',
        '@x',
        'xyz.transition.#a.@x.start',
        'xyz.transition.#a.@x.finish',
        'xyz.state.#a.exit.start',
        'xyz.state.#a.exit.finish',
        'xyz.state.#a.exit',
        'xyz.state.#x.enter',
        'xyz.state.#x.entry.start',
        'xyz.action.!y.start',
        'xyz.event.Y_EVENT.raised',
        'xyz.action.!y.finish',
        'xyz.state.#x.entry.finish',
        'Y_EVENT',
        'xyz.transition.#x.Y_EVENT.start',
        'xyz.transition.#x.Y_EVENT.finish',
        'xyz.state.#x.exit.start',
        'xyz.state.#x.exit.finish',
        'xyz.state.#x.exit',
        'xyz.state.#y.enter',
        'xyz.state.#y.entry.start',
        'xyz.action.!z.start',
        'xyz.event.@z.raised',
        'xyz.action.!z.finish',
        'xyz.state.#y.entry.finish',
        '@z',
        'xyz.transition.#y.@z.start',
        'xyz.transition.#y.@z.finish',
        'xyz.state.#y.exit.start',
        'xyz.state.#y.exit.finish',
        'xyz.state.#y.exit',
        'xyz.state.#z.enter',
        'xyz.state.#z.entry.start',
        'xyz.state.#z.entry.finish',
    ]);
});

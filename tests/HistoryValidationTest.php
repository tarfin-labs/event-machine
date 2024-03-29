<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsTimerValidValidationGuard;

test('machine validation exception from a past event is not rethrown during a subsequent successful transition', function (): void {
    $machine = Machine::withDefinition(MachineDefinition::define(config: [
        'initial' => 'green',
        'states'  => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'yellow',
                        'guards' => IsTimerValidValidationGuard::class.':2',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]));

    expect(fn () => $machine->send([
        'type'    => 'TIMER',
        'payload' => [
            'value' => 1,
        ],
    ]))->toThrow(MachineValidationException::class);

    expect($machine->state->history->pluck('type')->toArray())->toBe([
        'machine.start',
        'machine.state.green.enter',
        'machine.state.green.entry.start',
        'machine.state.green.entry.finish',
        'TIMER',
        'machine.transition.green.TIMER.start',
        'machine.guard.IsTimerValidValidationGuard.fail',
        'machine.transition.green.TIMER.fail',
    ]);

    expect(fn () => $machine->send([
        'type'    => 'TIMER',
        'payload' => [
            'value' => 3,
        ],
    ]))->not()->toThrow(MachineValidationException::class);

    expect($machine->state->history->pluck('type')->toArray())->toBe([
        'machine.start',
        'machine.state.green.enter',
        'machine.state.green.entry.start',
        'machine.state.green.entry.finish',
        'TIMER',
        'machine.transition.green.TIMER.start',
        'machine.guard.IsTimerValidValidationGuard.fail',
        'machine.transition.green.TIMER.fail',
        'TIMER',
        'machine.transition.green.TIMER.start',
        'machine.guard.IsTimerValidValidationGuard.pass',
        'machine.transition.green.TIMER.finish',
        'machine.state.green.exit.start',
        'machine.state.green.exit.finish',
        'machine.state.green.exit',
        'machine.state.yellow.enter',
        'machine.state.yellow.entry.start',
        'machine.state.yellow.entry.finish',
    ]);
});

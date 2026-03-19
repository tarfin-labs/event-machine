<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

it('records MACHINE_ENTRY internal events for root entry actions', function (): void {
    $machine = TestMachine::define([
        'id'      => 'demo',
        'initial' => 'idle',
        'context' => [],
        'entry'   => 'rootEntryAction',
        'states'  => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'rootEntryAction' => function (ContextManager $context): void {},
        ],
    ]);

    $types = $machine->machine()->state->history->pluck('type')->toArray();

    // Root entry events should use machine-level naming (demo.entry.start/finish)
    // NOT state-level naming (demo.state.idle.entry.start)
    expect($types)->toContain('demo.entry.start')
        ->and($types)->toContain('demo.entry.finish')
        ->and($types[1])->toBe('demo.entry.start')
        ->and($types[4])->toBe('demo.entry.finish');
});

it('records MACHINE_EXIT internal events for root exit actions', function (): void {
    $machine = TestMachine::define([
        'id'      => 'demo',
        'initial' => 'idle',
        'context' => [],
        'exit'    => 'rootExitAction',
        'states'  => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'rootExitAction' => function (ContextManager $context): void {},
        ],
    ]);

    $machine->send('GO');

    $types = $machine->machine()->state->history->pluck('type')->toArray();

    expect($types)->toContain('demo.exit.start')
        ->and($types)->toContain('demo.exit.finish')
        ->and($types)->toContain('demo.finish');

    // exit.start should come before finish
    $exitStart     = array_search('demo.exit.start', $types);
    $exitFinish    = array_search('demo.exit.finish', $types);
    $machineFinish = array_search('demo.finish', $types);

    expect($exitStart)->toBeLessThan($exitFinish)
        ->and($exitFinish)->toBeLessThan($machineFinish);
});

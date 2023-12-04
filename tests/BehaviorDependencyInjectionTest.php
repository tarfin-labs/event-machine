<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('it can inject ContextManager', function (): void {
    $machine = Machine::create(MachineDefinition::define(
        config: [
            'initial' => 'ready',
            'states'  => [
                'ready' => [
                    'on' => [
                        'CTX' => ['actions' => 'contextAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'contextAction' => function (ContextManager $c): void {
                    expect($c)->toBeInstanceOf(ContextManager::class);
                },
            ],
        ],
    ));

    $machine->send(['type' => 'CTX']);
});

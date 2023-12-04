<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('it can inject requested parameters', function (): void {
    $machine = Machine::create(MachineDefinition::define(
        config: [
            'context' => [
                'value' => 1,
            ],
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
                'contextAction' => function (
                    ContextManager $c,
                    EventBehavior $e,
                    State $s,
                    EventCollection $ec
                ): void {
                    expect($c)->toBeInstanceOf(ContextManager::class);
                    expect($c->value)->toBe(1);

                    expect($e)->toBeInstanceOf(EventBehavior::class);
                    expect($e->type)->toBe('CTX');

                    expect($s)->toBeInstanceOf(State::class);
                    expect($s->value)->toBe(['machine.ready']);

                    expect($ec)->toBeInstanceOf(EventCollection::class);
                    expect($ec->count())->toBe(7);
                },
            ],
        ],
    ));

    $machine->send(['type' => 'CTX']);
});

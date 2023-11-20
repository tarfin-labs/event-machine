<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\MachineWithScenarios;

it('scenarios run if scenarios enabled', function (): void {
    $machine = MachineWithScenarios::create();

    $state = $machine->send(['type' => 'EVENT_B', 'payload' => ['scenario' => 'test']]);

    expect($state)
        ->toBeInstanceOf(State::class)
        ->and($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.stateC'])
        ->and($state->context->count)->toBe(-1);

    $state = $machine->send('EVENT_D');

    expect($state)
        ->toBeInstanceOf(State::class)
        ->and($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.stateA'])
        ->and($state->context->count)->toBe(-2);
});

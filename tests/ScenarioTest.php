<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioMachine;

it('asd', function (): void {
    $machine = ScenarioMachine::create();

    $state = $machine->send(['type' => 'EVENT_B', 'payload' => ['scenario' => 'test']]);

    expect($state)
        ->toBeInstanceOf(State::class)
        ->and($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateC']);
});

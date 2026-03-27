<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\MachineWithScenarios;

/*
 * @deprecated Tests for the old scenario system. Will be removed in next major version.
 */
it('scenarios run if scenarios enabled', function (): void {
    $machine = MachineWithScenarios::create();

    $state = $machine->send(['type' => 'EVENT_B', 'payload' => ['scenarioType' => 'test']]);

    expect($state)
        ->toBeInstanceOf(State::class)
        ->and($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.state_c'])
        ->and($state->context->count)->toBe(-2);

    $state = $machine->send('EVENT_D');

    expect($state)
        ->toBeInstanceOf(State::class)
        ->and($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.state_a'])
        ->and($state->context->count)->toBe(-3);
});

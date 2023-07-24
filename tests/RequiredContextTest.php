<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsOddGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\IsOddAction;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

test('context values can be required for guards and actions', function (): void {
    $machineDefinition = MachineDefinition::define(config: [
        'context' => [
            'counts' => [
                'oddCount' => null,
            ],
        ],
        'states' => [
            'stateA' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'stateB',
                        'guards' => IsOddGuard::class,
                    ],
                    'EVENT2' => [
                        'target'  => 'stateB',
                        'actions' => IsOddAction::class,
                    ],
                ],
            ],
            'stateB' => [],
        ],
    ]);

    expect(fn () => $machineDefinition->transition(event: ['type' => 'EVENT']))
        ->toThrow(
            exception: MissingMachineContextException::class,
            exceptionMessage: '`counts.oddCount` is missing in context.',
        );

    expect(fn () => $machineDefinition->transition(event: ['type' => 'EVENT2']))
        ->toThrow(
            exception: MissingMachineContextException::class,
            exceptionMessage: '`counts.oddCount` is missing in context.',
        );
});

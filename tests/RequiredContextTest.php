<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsOddGuard;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

test('context values can be required for guards', function (): void {
    $machineDefinition = MachineDefinition::define(config: [
        'context' => [],
        'states'  => [
            'stateA' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'stateB',
                        'guards' => IsOddGuard::class,
                    ],
                ],
            ],
            'stateB' => [],
        ],
    ]);

    expect(fn () => $machineDefinition->transition(event: ['type' => 'EVENT']))
        ->toThrow(
            exception: MissingMachineContextException::class,
            exceptionMessage: '`count` is missing in context.',
        );
});

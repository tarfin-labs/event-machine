<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\IsOddMachine;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

test('context values can be required for guards', function (): void {
    $machine = IsOddMachine::start();

    expect(fn () => $machine->send(event: ['type' => 'EVENT']))
        ->toThrow(
            exception: MissingMachineContextException::class,
            exceptionMessage: '`value` is missing in context.',
        );
});

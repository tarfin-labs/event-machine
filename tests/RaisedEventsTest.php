<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

test('actions can raise events', function (): void {
    $machine = XyzMachine::start();

    expect($machine->state->matches('#z'))->toBeTrue();
    expect($machine->state->context->value)->toBe('xyz');
});

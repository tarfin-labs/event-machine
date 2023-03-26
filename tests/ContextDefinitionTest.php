<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

test('a machine definition can have context', function (): void {
    $machine = MachineDefinition::define([
        'id'      => 'test',
        'context' => [
            'foo' => 'bar',
        ],
    ]);

    $context = $machine->context;

    expect($context)->toBeInstanceOf(ContextDefinition::class);
    expect($context->get('foo'))->toBe('bar');
});

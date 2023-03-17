<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

test('simple flat parallel machine', function (): void {
    $machine = Machine::define([
        'type'   => 'parallel',
        'states' => [
            'foo' => [],
            'bar' => [],
            'baz' => [
                'initial' => 'one',
                'states'  => [
                    'one' => [
                        'on' => [
                            'E' => 'two',
                        ],
                    ],
                    'two' => [],
                ],
            ],
        ],
    ]);
})->skip();

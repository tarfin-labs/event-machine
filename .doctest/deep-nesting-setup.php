<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

$definition = MachineDefinition::define([
    'id'      => 'deep',
    'initial' => 'root',
    'states'  => [
        'root' => [
            'type'   => 'parallel',
            'states' => [
                'branch1' => [
                    'initial' => 'leaf',
                    'states'  => [
                        'leaf' => [
                            'type'   => 'parallel',
                            'states' => [
                                'subleaf1' => [
                                    'initial' => 'a',
                                    'states'  => [
                                        'a' => ['on' => ['GO1' => 'b']],
                                        'b' => [],
                                    ],
                                ],
                                'subleaf2' => [
                                    'initial' => 'x',
                                    'states'  => [
                                        'x' => ['on' => ['GO2' => 'y']],
                                        'y' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'branch2' => [
                    'initial' => 'waiting',
                    'states'  => [
                        'waiting'  => ['on' => ['DONE' => 'finished']],
                        'finished' => [],
                    ],
                ],
            ],
        ],
    ],
]);

// $state is initialized by the block that uses this bootstrap

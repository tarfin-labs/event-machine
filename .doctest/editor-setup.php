<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

$definition = MachineDefinition::define([
    'id'      => 'editor',
    'initial' => 'active',
    'states'  => [
        'active' => [
            'type'   => 'parallel',
            'states' => [
                'document' => [
                    'initial' => 'editing',
                    'states'  => [
                        'editing' => ['on' => ['SAVE' => 'saving']],
                        'saving'  => ['on' => ['SAVED' => 'editing']],
                    ],
                ],
                'format' => [
                    'initial' => 'normal',
                    'states'  => [
                        'normal' => ['on' => ['BOLD' => 'bold']],
                        'bold'   => ['on' => ['NORMAL' => 'normal']],
                    ],
                ],
            ],
        ],
    ],
]);

$state = $definition->getInitialState();

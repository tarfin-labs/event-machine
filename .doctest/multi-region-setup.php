<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

$definition = MachineDefinition::define(
    config: [
        'id'      => 'editor',
        'initial' => 'active',
        'context' => ['value' => ''],
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'editing' => [
                        'initial' => 'idle',
                        'states'  => [
                            'idle' => [
                                'on' => [
                                    'CHANGE' => [
                                        'target'  => 'modified',
                                        'actions' => 'updateValueAction',
                                    ],
                                ],
                            ],
                            'modified' => [],
                        ],
                    ],
                    'status' => [
                        'initial' => 'saved',
                        'states'  => [
                            'saved'   => ['on' => ['CHANGE' => 'unsaved']],
                            'unsaved' => ['on' => ['SAVE' => 'saved']],
                        ],
                    ],
                ],
            ],
        ],
    ],
    behavior: [
        'actions' => [
            'updateValueAction' => fn (ContextManager $ctx) => $ctx->set('value', 'changed'),
        ],
    ]
);

$state = $definition->getInitialState();

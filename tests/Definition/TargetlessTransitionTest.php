<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

it('handles null target as targetless transition', function (): void {
    TestMachine::define([
        'id'      => 'test',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => [
                    'PING' => null,
                ],
            ],
        ],
    ], ['context' => GenericContext::class])
        ->send('PING')
        ->assertState('idle');
});

it('handles empty string target as targetless transition', function (): void {
    TestMachine::define([
        'id'      => 'test',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => [
                    'PING' => '',
                ],
            ],
        ],
    ], ['context' => GenericContext::class])
        ->send('PING')
        ->assertState('idle');
});

it('handles empty array target as targetless transition', function (): void {
    TestMachine::define([
        'id'      => 'test',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => [
                    'PING' => [],
                ],
            ],
        ],
    ], ['context' => GenericContext::class])
        ->send('PING')
        ->assertState('idle');
});

it('handles empty string in target key as targetless transition', function (): void {
    TestMachine::define([
        'id'      => 'test',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => [
                    'PING' => [
                        'target'  => '',
                        'actions' => 'incrementAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'incrementAction' => function (ContextManager $context): void {
                $context->set('count', $context->get('count') + 1);
            },
        ],
    ])
        ->send('PING')
        ->assertState('idle')
        ->assertContext('count', 1);
});

it('handles null in target key as targetless transition', function (): void {
    TestMachine::define([
        'id'      => 'test',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => [
                    'PING' => [
                        'target'  => null,
                        'actions' => 'incrementAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'incrementAction' => function (ContextManager $context): void {
                $context->set('count', $context->get('count') + 1);
            },
        ],
    ])
        ->send('PING')
        ->assertState('idle')
        ->assertContext('count', 1);
});

it('handles empty string with actions as targetless transition', function (): void {
    TestMachine::define([
        'id'      => 'test',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => [
                    'PING' => [
                        'target'  => '',
                        'actions' => 'incrementAction',
                    ],
                    'GO' => 'done',
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'incrementAction' => function (ContextManager $context): void {
                $context->set('count', $context->get('count') + 1);
            },
        ],
    ])
        ->send('PING')
        ->send('PING')
        ->assertState('idle')
        ->assertContext('count', 2)
        ->send('GO')
        ->assertState('done');
});

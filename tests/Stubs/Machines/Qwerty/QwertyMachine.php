<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty;

use Illuminate\Support\Facades\Log;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\EEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\QEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\REvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\TEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Actions\TAction;

class QwertyMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'xyz',
                'context' => [
                    'count' => 1,
                ],
                'initial' => '#q',
                'states'  => [
                    '#q' => [
                        'on' => [
                            QEvent::class => [
                                'target'  => '#w',
                                'actions' => 'log',
                            ],
                        ],
                    ],
                    '#w' => [
                        'on' => [
                            '@always' => [
                                'target'  => '#e',
                                'actions' => 'log',
                            ],
                        ],
                    ],
                    '#e' => [
                        'on' => [
                            EEvent::class => [
                                'target'  => '#r',
                                'actions' => 'log',
                            ],
                        ],
                    ],
                    '#r' => [
                        'on' => [
                            REvent::class => [
                                'target'  => '#t',
                                'actions' => 'log',
                            ],
                        ],
                    ],
                    '#t' => [
                        'entry' => TAction::class,
                        'on'    => [
                            TEvent::class => [
                                'target'  => '#y',
                                'actions' => 'log',
                            ],
                        ],
                    ],
                    '#y' => [
                        'entry' => 'log',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'log' => function (ContextManager $context, EventBehavior $eventBehavior): void {
                        Log::debug($eventBehavior->actor(context: $context));

                        $context->count++;
                    },
                ],
            ],
        );
    }
}

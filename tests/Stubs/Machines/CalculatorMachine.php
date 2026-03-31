<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class CalculatorMachine extends Machine
{
    public static function definition(): ?MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'ready',
                'context' => [
                    'total' => 0,
                ],
                'states' => [
                    'ready' => [
                        'on' => [
                            'ADD' => ['actions' => 'additionAction'],
                            'SUB' => ['actions' => 'subtractionAction'],
                            'MUL' => ['actions' => 'multiplicationAction'],
                            'DIV' => ['actions' => 'divisionAction'],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'additionAction' => function (ContextManager $c, EventDefinition $e): void {
                        $c->total = $c->total + $e->payload['value'];
                    },
                    'subtractionAction' => function (ContextManager $ctx, EventDefinition $evt): void {
                        $ctx->total = $ctx->total - $evt->payload['value'];
                    },
                    'multiplicationAction' => function (ContextManager $contextManager, EventDefinition $eventDefinition): void {
                        $contextManager->total = $contextManager->total * $eventDefinition->payload['value'];
                    },
                    'divisionAction' => function (ContextManager $manager, EventDefinition $event): void {
                        $manager->total = $manager->total / $event->payload['value'];
                    },
                ],
            ],
        );
    }
}
